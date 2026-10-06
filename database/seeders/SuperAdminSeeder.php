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

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Franco Acqua',
                'password' => Hash::make($password),
                'role' => 'admin',
                'company_id' => null,
            ]
        );

        // is_super_admin no es asignable masivamente a propósito.
        $user->forceFill(['is_super_admin' => true])->save();
    }
}
