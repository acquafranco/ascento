<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        dd('SuperAdminSeeder');
        User::updateOrCreate(

        ['email' => 'franco@ascento.online'],

        [

            'name' => 'Franco Acqua',

            'password' => Hash::make(env('SUPER_ADMIN_PASSWORD')),

            'role' => 'admin',

            'is_super_admin' => true,

            'company_id' => null,

        ]

    );
    }
}
