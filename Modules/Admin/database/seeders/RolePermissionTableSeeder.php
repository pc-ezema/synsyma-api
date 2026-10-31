<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Route;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;

class RolePermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        $guard = 'admin';

        // Create Roles
        $role = Role::findOrCreate('super_admin', $guard);
        $role->syncPermissions(Permission::where('guard_name', $guard)->get());

        $email = config('admin.super_admin.email');
        $password = config('admin.super_admin.password');

        if (! $email || ! $password) {
            $this->command?->error('Set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env, then run again.');
            return;
        }

        // firstOrCreate: re-running the seeder never resets an existing password.
        $admin = Admin::firstOrCreate(
            ['email' => $email],
            ['name' => config('admin.super_admin.name'), 'password' => bcrypt($password)],
        );

        $admin->assignRole($role);
    }
}
