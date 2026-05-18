<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // License management
            'licenses.view',
            'licenses.create',
            'licenses.update',
            'licenses.revoke',
            'licenses.delete',

            // Organization management
            'organizations.view',
            'organizations.create',
            'organizations.update',
            'organizations.delete',

            // API Client management
            'api-clients.view',
            'api-clients.create',
            'api-clients.update',
            'api-clients.regenerate-secret',

            // Audit logs
            'audit-logs.view',
            'audit-logs.export',

            // Dashboard
            'dashboard.view',

            // User management
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.assign-roles',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo([
            'licenses.view', 'licenses.create', 'licenses.update', 'licenses.revoke',
            'organizations.view', 'organizations.create', 'organizations.update',
            'api-clients.view', 'api-clients.create', 'api-clients.update',
            'audit-logs.view', 'audit-logs.export',
            'dashboard.view',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->givePermissionTo([
            'licenses.view',
            'organizations.view',
            'api-clients.view',
            'audit-logs.view',
            'dashboard.view',
        ]);

        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $operator->givePermissionTo([
            'licenses.view', 'licenses.create', 'licenses.update',
            'organizations.view',
            'api-clients.view',
            'audit-logs.view',
            'dashboard.view',
        ]);
    }
}
