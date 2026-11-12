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
        // Dashboard
        'dashboard.view'           => 'View the dashboard overview',

        // Chat
        'chat.view'                => 'View customer conversations and messages',
        'chat.reply'               => 'Reply to customers in chat',
        'chat.moderate'            => 'Close, reopen, assign conversations and delete messages',

        // Administrators
        'admins.view'              => 'View the list of administrators',
        'admins.create'            => 'Create new administrators',
        'admins.update'            => 'Edit administrators and enable or disable their accounts',
        'admins.delete'            => 'Delete administrators',
        'admins.resend'            => 'Send new login details to an administrator (sets a new temporary password)',
        'admins.roles.sync'        => 'Replace all roles on an administrator',
        'admins.roles.assign'      => 'Assign a role to an administrator',
        'admins.roles.remove'      => 'Remove a role from an administrator',
        'admins.permissions.give'  => 'Give an administrator an extra permission',
        'admins.permissions.revoke'=> 'Take an extra permission away from an administrator',

        // Roles
        'roles.index'              => 'View the list of roles',
        'roles.show'               => 'View a role and its permissions',
        'roles.admins'             => 'View the administrators who have a role',
        'roles.store'              => 'Create roles',
        'roles.update'             => 'Rename roles and change their permissions',
        'roles.destroy'            => 'Delete roles',
        'roles.permissions.sync'   => 'Replace all permissions on a role',

        // Permissions
        'permission.view'          => 'View the list of permissions',
        'permission.sync'          => 'Sync permissions from the API routes',

        // Support Contacts
        'support.view'             => 'See the support contacts shown on the app Help screen',
        'support.create'           => 'Add a support phone number, email or WhatsApp number',
        'support.update'           => 'Edit or switch off a support contact',
        'support.delete'           => 'Remove a support contact',
        'deletions.view'           => 'See the record of accounts users deleted',

        // Services
        'categories.view'           => 'See the service categories',
        'categories.create'         => 'Add a service category',
        'categories.update'         => 'Edit or switch off a service category',
        'categories.delete'         => 'Remove an empty service category',
        'services.view'             => 'See the services and their prices',
        'services.create'           => 'Add a service',
        'services.update'           => 'Edit a service, its price or switch it off',
        'services.delete'           => 'Remove a service',

        // Contractors
        'contractors.view'          => 'See the contractors and their documents',
        'contractors.create'        => 'Add a contractor',
        'contractors.update'        => 'Edit, approve, reject or suspend a contractor',
        'contractors.delete'        => 'Remove a contractor',
        'contractors.documents.view' => 'See the documents for a contractor',
        'contractors.documents.create' => 'Add a document for a contractor',
        'contractors.documents.update' => 'Edit a document for a contractor',
        'contractors.documents.delete' => 'Remove a document for a contractor',
        'campaigns.view'            => 'See the QR codes for contractor sign-up',
        'campaigns.manage'          => 'Create, switch off or remove QR codes',

        // Bookings
        'bookings.view'             => 'See the bookings and their details',
        'bookings.manage'           => 'Change the status of a booking, assign a contractor or add a note',

        // Payments
        'payments.view'             => 'See the payments and their details',

        // Banners
        'banners.view'             => 'See the list of banners',
        'banners.create'           => 'Add a banner',
        'banners.update'           => 'Edit a banner or switch it off',
        'banners.delete'           => 'Remove a banner',

        // Promos
        'promos.view'               => 'See the list of promo codes',
        'promos.create'             => 'Add a promo code',
        'promos.update'             => 'Edit a promo code or switch it off',
        'promos.delete'             => 'Remove a promo code',

        // Payouts
        'payouts.view'              => 'See the payouts and their details',
        'payouts.manage'            => 'Mark a payout as paid and send the contractor a notification',

        // Review
        'reviews.view'              => 'See the list of reviews',
        'reviews.moderate'          => 'Moderate reviews',

        // User
        'users.view'                => 'See the list of users',
        'users.manage'              => 'Switch off or delete a user',
        'users.change_email'        => 'Change the email address of a user',

        // Settings
        'settings.view'             => 'See the settings',
        'settings.manage'           => 'Manage the business details, booking time rules, default settings and support contacts',
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
