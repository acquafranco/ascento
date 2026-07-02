<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // ADMIN
        User::updateOrCreate(
            [
                'email' => 'francohares@gmail.com'
            ],
            [
                'name' => 'Franco',
                'password' => Hash::make('1234'),
                'role' => 'admin',
            ]
        );

        // TÉCNICO
        User::updateOrCreate(
            [
                'email' => 'test@example.com'
            ],
            [
                'name' => 'Técnico',
                'password' => Hash::make(
                    '1234'
                ),
                'role' => 'technician',
            ]
        );
    }
}
