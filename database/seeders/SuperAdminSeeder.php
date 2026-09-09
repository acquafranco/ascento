<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.super_admin_email');
        $password = config('app.super_admin_password');

        if (!$email || !$password) {
            throw new \RuntimeException(
                'SUPER_ADMIN_EMAIL o SUPER_ADMIN_PASSWORD no están configurados.'
            );
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Franco Acqua',
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_super_admin' => true,
                'company_id' => null,
            ]
        );
    }
}
