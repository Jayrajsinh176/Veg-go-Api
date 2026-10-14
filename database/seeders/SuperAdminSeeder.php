<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Create (or reset) the default super admin account.
     *
     * php artisan db:seed --class=SuperAdminSeeder
     */
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'vezgosuper@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => '12345678', // hashed by the Admin model cast
                'role' => Admin::ROLE_SUPER_ADMIN,
                'status' => 'active',
            ]
        );
    }
}
