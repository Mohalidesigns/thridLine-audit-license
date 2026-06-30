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

        // Default demo accounts use a well-known password — never seed them in
        // production unless explicitly opted in via SEED_DEMO_USERS=true.
        if (app()->environment('production') && !env('SEED_DEMO_USERS', false)) {
            $this->command?->warn('Skipping demo user seeding in production (set SEED_DEMO_USERS=true to override).');
            return;
        }

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
