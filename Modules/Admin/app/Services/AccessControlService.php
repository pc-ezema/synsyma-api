<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AccessControlException;
use Modules\Admin\Models\Admin;
use Modules\Admin\Transformers\AdminResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlService
{
    public const GUARD = 'admin';
    public const SUPER_ADMIN = 'super_admin';
    public const DEFAULT_ROLE = 'admin';

    private const SYSTEM_ROLES = [self::SUPER_ADMIN, self::DEFAULT_ROLE];

    // ---- Lookups ----

    public function role(string $id): Role
    {
        return Role::where('guard_name', self::GUARD)->find($id)
            ?? throw new AccessControlException('Role not found.', 404);
    }

    public function admin(string $id): Admin
    {
        return Admin::find($id) ?? throw new AccessControlException('Admin not found.', 404);
    }

    // ---- Roles ----

    public function roles(): Collection
    {
        $pivot = config('permission.table_names.model_has_roles', 'model_has_roles');
        $key = config('permission.column_names.role_pivot_key') ?: 'role_id';

        $members = DB::table($pivot)
            ->where('model_type', (new Admin)->getMorphClass())
            ->selectRaw("{$key} as role_key, count(*) as total")
            ->groupBy($key)
            ->pluck('total', 'role_key');

        return Role::where('guard_name', self::GUARD)
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->roleArray($role, (int) ($members[$role->getKey()] ?? 0)))
            ->values();
    }

    public function roleDetails(Role $role): array
    {
        return $this->roleArray($role, $this->adminCount($role), true);
    }

    public function createRole(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => self::GUARD]);

            if (! empty($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            return $this->roleArray($role->refresh(), 0, true);
        });
    }

    public function updateRole(Role $role, array $data): array
    {
        $name = $data['name'] ?? $role->name;

        if ($this->isSystem($role) && $name !== $role->name) {
            throw new AccessControlException('System roles cannot be renamed.', 403);
        }

        if (array_key_exists('permissions', $data)) {
            $this->assertPermissionsEditable($role);
        }

        DB::transaction(function () use ($role, $name, $data) {
            $role->update(['name' => $name]);

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions'] ?? []);
            }
        });

        return $this->roleArray($role->refresh(), $this->adminCount($role), true);
    }

    public function deleteRole(Role $role): array
    {
        if ($this->isSystem($role)) {
            throw new AccessControlException('System roles cannot be deleted.', 403);
        }

        return DB::transaction(function () use ($role) {
            $default = Role::findOrCreate(self::DEFAULT_ROLE, self::GUARD);
            $reassigned = 0;

            // Only admins left with no role at all move to the default role.
            Admin::role($role->name, self::GUARD)->get()->each(function (Admin $admin) use ($role, $default, &$reassigned) {
                $admin->removeRole($role);

                if ($admin->roles()->count() === 0) {
                    $admin->assignRole($default);
                    $reassigned++;
                }
            });

            $role->delete();

            return ['deleted' => true, 'reassigned_admins' => $reassigned, 'default_role' => $default->name];
        });
    }

    public function syncRolePermissions(Role $role, array $names): array
    {
        $this->assertPermissionsEditable($role);

        $role->syncPermissions($names);

        return $this->roleArray($role->refresh(), $this->adminCount($role), true);
    }

    public function roleAdmins(Role $role): array
    {
        return Admin::role($role->name, self::GUARD)->orderBy('name')->get()
            ->map(fn (Admin $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'is_active' => $a->is_active,
            ])
            ->all();
    }

    // ---- Permissions ----

    public function permissions(): array
    {
        return Permission::where('guard_name', self::GUARD)->orderBy('name')->get()
            ->map(fn (Permission $p) => [
                'id' => $p->getKey(),
                'name' => $p->name,
                'group' => Str::before($p->name, '.'),
                'description' => $p->description,
            ])
            ->all();
    }

    /**
     * Creates permissions that routes ask for (can:chat.view, permission:a|b).
     * Permissions no route uses are only reported, and removed when $prune is true.
     */
    public function syncPermissionsFromRoutes(bool $prune = false): array
    {
        $found = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m))
            ->flatMap(function (string $m) {
                if (str_starts_with($m, 'permission:')) {
                    return explode('|', substr($m, 11));
                }
                if (str_starts_with($m, 'can:')) {
                    return [explode(',', substr($m, 4))[0]];
                }

                return [];
            })
            ->map(fn ($p) => trim($p))
            ->filter(fn ($p) => preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $p) === 1) // dotted names only
            ->unique()
            ->values();

        $result = DB::transaction(function () use ($found, $prune) {
            $existing = Permission::where('guard_name', self::GUARD)->pluck('name');

            $created = $found->diff($existing)->values();
            foreach ($created as $name) {
                Permission::findOrCreate($name, self::GUARD);
            }

            $unused = $existing->diff($found)->values();
            if ($prune && $unused->isNotEmpty()) {
                Permission::where('guard_name', self::GUARD)->whereIn('name', $unused)->get()
                    ->each(function (Permission $p) {
                        $p->roles()->detach();
                        $p->delete();
                    });
            }

            Role::findOrCreate(self::SUPER_ADMIN, self::GUARD)
                ->syncPermissions(Permission::where('guard_name', self::GUARD)->get());

            return [
                'total_in_routes' => $found->count(),
                'created' => $created->all(),
                'not_used_by_any_route' => $unused->all(),
                'removed' => $prune ? $unused->all() : [],
            ];
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    // ---- Assigning access to an admin ----

    public function setAdminRoles(Admin $actor, Admin $target, array $roleIds): array
    {
        $this->assertNotSelf($actor, $target);

        $names = Role::where('guard_name', self::GUARD)->whereIn('id', $roleIds)->pluck('name')->all();

        $hadSuper = $target->hasRole(self::SUPER_ADMIN);
        $willHaveSuper = in_array(self::SUPER_ADMIN, $names, true);

        if ($hadSuper !== $willHaveSuper) {
            $this->assertCanChangeSuperAdmin($actor, $target, removing: $hadSuper);
        }

        if ($names === []) {
            $names = [Role::findOrCreate(self::DEFAULT_ROLE, self::GUARD)->name];
        }

        $target->syncRoles($names);

        return $this->adminAccess($target);
    }

    public function assignRole(Admin $actor, Admin $target, Role $role): array
    {
        $this->assertNotSelf($actor, $target);

        if ($role->name === self::SUPER_ADMIN) {
            $this->assertCanChangeSuperAdmin($actor, $target, removing: false);
        }

        $target->assignRole($role); // safe to repeat

        return $this->adminAccess($target);
    }

    public function removeRole(Admin $actor, Admin $target, Role $role): array
    {
        $this->assertNotSelf($actor, $target);

        if (! $target->hasRole($role)) {
            throw new AccessControlException('That role is not assigned to this admin.', 404);
        }

        if ($role->name === self::SUPER_ADMIN) {
            $this->assertCanChangeSuperAdmin($actor, $target, removing: true);
        }

        $target->removeRole($role);

        if ($target->roles()->count() === 0) {
            $target->assignRole(Role::findOrCreate(self::DEFAULT_ROLE, self::GUARD));
        }

        return $this->adminAccess($target);
    }

    public function givePermission(Admin $actor, Admin $target, string $name): array
    {
        $this->assertNotSelf($actor, $target);

        $target->givePermissionTo($this->permission($name));

        return $this->adminAccess($target);
    }

    public function revokePermission(Admin $actor, Admin $target, string $name): array
    {
        $this->assertNotSelf($actor, $target);

        $target->revokePermissionTo($this->permission($name));

        return $this->adminAccess($target);
    }

    // ---- Helpers ----

    private function permission(string $name): Permission
    {
        return Permission::where('guard_name', self::GUARD)->where('name', $name)->first()
            ?? throw new AccessControlException("Permission '{$name}' does not exist.", 422);
    }

    private function isSystem(Role $role): bool
    {
        return in_array($role->name, self::SYSTEM_ROLES, true);
    }

    private function adminCount(Role $role): int
    {
        return Admin::role($role->name, self::GUARD)->count();
    }

    private function assertPermissionsEditable(Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN) {
            throw new AccessControlException('The super admin role always has every permission.', 403);
        }
    }

    /** Stops people from widening their own access. */
    private function assertNotSelf(Admin $actor, Admin $target): void
    {
        if ($actor->is($target) && ! $actor->hasRole(self::SUPER_ADMIN)) {
            throw new AccessControlException('You cannot change your own access.', 403);
        }
    }

    private function assertCanChangeSuperAdmin(Admin $actor, Admin $target, bool $removing): void
    {
        if (! $actor->hasRole(self::SUPER_ADMIN)) {
            throw new AccessControlException('Only a super admin can change the super admin role.', 403);
        }

        if ($removing && $this->isProtected($target)) {
            throw new AccessControlException('This account must always stay a super admin.', 403);
        }
    }

    /** The account named in SUPER_ADMIN_EMAIL can never lose super admin. */
    private function isProtected(Admin $admin): bool
    {
        $email = strtolower((string) config('admin.super_admin.email'));

        return $email !== '' && strtolower($admin->email) === $email;
    }

    private function adminAccess(Admin $admin): array
    {
        $admin->refresh();

        return array_merge((new AdminResource($admin))->resolve(), [
            'direct_permissions' => $admin->getDirectPermissions()->pluck('name')->values()->all(),
        ]);
    }

    private function roleArray(Role $role, ?int $admins = null, bool $withPermissions = false): array
    {
        $out = [
            'id' => $role->getKey(),
            'name' => $role->name,
            'label' => Str::headline($role->name),
            'is_system' => $this->isSystem($role),
            'admins_count' => $admins,
            'permissions_count' => $role->permissions_count ?? $role->permissions()->count(),
        ];

        if ($withPermissions) {
            $out['permissions'] = $role->permissions()->pluck('name')->sort()->values()->all();
        }

        return $out;
    }
}