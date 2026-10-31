<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissionsTableSeeder extends Seeder
{
    /**
     * Description mapping for all permissions found in routes.
     */
    protected $permissionDescriptions = [
        
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $routes = Route::getRoutes();
        $permissions = collect();

        foreach ($routes as $route) {
            if (empty($route->uri()) || $route->uri() === '/') {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $permString = str_replace('permission:', '', $middleware);
                    $parts = explode('|', $permString);
                    
                    foreach ($parts as $perm) {
                        $perm = trim($perm);
                        if (!empty($perm)) {
                            $permissions->push($perm);
                        }
                    }
                }
            }
        }

        $permissions = $permissions->unique()->values();

        // Get all existing permission names for deletion check
        $existingPermissions = Permission::where('guard_name', 'admin')->pluck('name')->toArray();
        $toDelete = array_diff($existingPermissions, $permissions->toArray());

        // Delete permissions that are no longer used
        if (!empty($toDelete)) {
            Permission::whereIn('name', $toDelete)->delete();
        }

        // Create or update permissions with descriptions
        foreach ($permissions as $permissionName) {
            $description = $this->permissionDescriptions[$permissionName] ?? null;
            
            Permission::updateOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'admin'
                ],
                [
                    'description' => $description
                ]
            );
        }

        // Sync superadmin role
        $superAdmin = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'admin'
        ]);
        $superAdmin->syncPermissions($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info("✅ Synced " . $permissions->count() . " permissions");
        $this->command->info($permissions->join("\n"));
    }
}
