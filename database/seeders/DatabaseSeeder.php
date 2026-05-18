<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Create super-admin user
        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@auditpro.com',
            'password' => bcrypt('password'),
        ]);
        $superAdmin->assignRole('super-admin');

        // Create admin user
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@thirdline.ng',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        // Create viewer user
        $viewer = User::factory()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@thirdline.ng',
            'password' => bcrypt('password'),
        ]);
        $viewer->assignRole('viewer');
    }
}
