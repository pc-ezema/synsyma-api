<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Admin\Services\AccessControlService;

class AccessControlController extends Controller
{
    private const NAME_RULE = '/^[a-z][a-z0-9_]{1,48}$/';

    public function __construct(private AccessControlService $access)
    {
    }

    // ---- Roles ----

    public function roles(): JsonResponse
    {
        return response()->json(['data' => $this->access->roles()]);
    }

    public function showRole(string $role): JsonResponse
    {
        return response()->json(['data' => $this->access->roleDetails($this->access->role($role))]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->normalizeName($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'regex:'.self::NAME_RULE,
                Rule::notIn([AccessControlService::SUPER_ADMIN]),
                Rule::unique('roles', 'name')->where('guard_name', AccessControlService::GUARD),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => $this->permissionRule(),
        ], ['name.regex' => 'Use lowercase letters, numbers and underscores only.']);

        return response()->json([
            'message' => 'Role created.',
            'data' => $this->access->createRole($data),
        ], 201);
    }

    public function updateRole(Request $request, string $role): JsonResponse
    {
        $model = $this->access->role($role);
        $this->normalizeName($request);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'regex:'.self::NAME_RULE,
                Rule::unique('roles', 'name')->where('guard_name', AccessControlService::GUARD)->ignore($model->getKey()),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => $this->permissionRule(),
        ], ['name.regex' => 'Use lowercase letters, numbers and underscores only.']);

        return response()->json([
            'message' => 'Role updated.',
            'data' => $this->access->updateRole($model, $data),
        ]);
    }

    public function destroyRole(string $role): JsonResponse
    {
        return response()->json([
            'message' => 'Role deleted.',
            'data' => $this->access->deleteRole($this->access->role($role)),
        ]);
    }

    /** Replaces the role's permissions. Send an empty array to remove them all. */
    public function syncRolePermissions(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => $this->permissionRule(),
        ]);

        return response()->json([
            'message' => 'Permissions updated.',
            'data' => $this->access->syncRolePermissions($this->access->role($role), $data['permissions']),
        ]);
    }

    public function roleAdmins(string $role): JsonResponse
    {
        return response()->json(['data' => $this->access->roleAdmins($this->access->role($role))]);
    }

    // ---- Permissions ----

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => $this->access->permissions()]);
    }

    public function syncPermissions(Request $request): JsonResponse
    {
        $request->validate(['prune' => ['sometimes', 'boolean']]);

        return response()->json([
            'message' => 'Permissions synced.',
            'data' => $this->access->syncPermissionsFromRoutes($request->boolean('prune')),
        ]);
    }

    // ---- Access for one admin ----

    /** Replaces all of an admin's roles. */
    public function syncAdminRoles(Request $request, string $admin): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::exists('roles', 'id')->where('guard_name', AccessControlService::GUARD)],
        ]);

        return response()->json([
            'message' => 'Roles updated.',
            'data' => $this->access->setAdminRoles($request->user(), $this->access->admin($admin), $data['roles']),
        ]);
    }

    public function assignRole(Request $request, string $admin, string $role): JsonResponse
    {
        return response()->json([
            'message' => 'Role assigned.',
            'data' => $this->access->assignRole($request->user(), $this->access->admin($admin), $this->access->role($role)),
        ]);
    }

    public function removeRole(Request $request, string $admin, string $role): JsonResponse
    {
        return response()->json([
            'message' => 'Role removed.',
            'data' => $this->access->removeRole($request->user(), $this->access->admin($admin), $this->access->role($role)),
        ]);
    }

    public function givePermission(Request $request, string $admin): JsonResponse
    {
        $data = $request->validate(['permission' => ['required', 'string', ...$this->permissionRule()]]);

        return response()->json([
            'message' => 'Permission added.',
            'data' => $this->access->givePermission($request->user(), $this->access->admin($admin), $data['permission']),
        ]);
    }

    public function revokePermission(Request $request, string $admin): JsonResponse
    {
        $data = $request->validate(['permission' => ['required', 'string', ...$this->permissionRule()]]);

        return response()->json([
            'message' => 'Permission removed.',
            'data' => $this->access->revokePermission($request->user(), $this->access->admin($admin), $data['permission']),
        ]);
    }

    // ---- Helpers ----

    private function permissionRule(): array
    {
        return ['string', Rule::exists('permissions', 'name')->where('guard_name', AccessControlService::GUARD)];
    }

    private function normalizeName(Request $request): void
    {
        if ($request->has('name')) {
            $request->merge(['name' => Str::lower(trim((string) $request->input('name')))]);
        }
    }
}