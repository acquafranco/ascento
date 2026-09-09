<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $env = file_get_contents(base_path('.env'));

        preg_match('/^SUPER_ADMIN_EMAIL=(.*)$/m', $env, $emailMatch);
        preg_match('/^SUPER_ADMIN_PASSWORD=(.*)$/m', $env, $passwordMatch);

        $email = trim($emailMatch[1] ?? '');
        $password = trim($passwordMatch[1] ?? '');

        if (!$email || !$password) {
            throw new \RuntimeException(
                'SUPER_ADMIN_EMAIL o SUPER_ADMIN_PASSWORD no están configurados en .env'
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
