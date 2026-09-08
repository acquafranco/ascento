<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
        {
            $password = config('app.super_admin_password');

            if (!$password) {
                throw new \RuntimeException(
                    'SUPER_ADMIN_PASSWORD no está configurada.'
                );
            }

            User::updateOrCreate(
                ['email' => 'franco@ascento.online'],
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
