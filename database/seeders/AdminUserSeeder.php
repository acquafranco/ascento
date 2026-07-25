<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | EMPRESA 1
        |--------------------------------------------------------------------------
        */

        $company1 = Company::updateOrCreate(
            [
                'slug' => 'cento-ascensores',
            ],
            [
                'name' => 'Cento Ascensores',
                'email' => 'contacto@cento.com',
                'phone' => '01111111111',
                'address' => 'Buenos Aires',
                'is_active' => true,
            ]
        );


        // ADMIN FRANCO
        User::updateOrCreate(
            [
                'email' => 'francohares@gmail.com',
            ],
            [
                'company_id' => $company1->id,
                'name' => 'Franco',
                'password' => Hash::make('1234'),
                'role' => 'admin',
                'job_type' => null,
            ]
        );


        // TÉCNICO FRANCO
        User::updateOrCreate(
            [
                'email' => 'tecnico@cento.com',
            ],
            [
                'company_id' => $company1->id,
                'name' => 'Técnico Franco',
                'password' => Hash::make('1234'),
                'role' => 'technician',
                'job_type' => 'Tecnico',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | EMPRESA 2
        |--------------------------------------------------------------------------
        */

        $company2 = Company::updateOrCreate(
            [
                'slug' => 'elevadores-del-sur',
            ],
            [
                'name' => 'Elevadores del Sur',
                'email' => 'contacto@elevadoresdelsur.com',
                'phone' => '02222222222',
                'address' => 'Buenos Aires',
                'is_active' => true,
            ]
        );


        // ADMIN EMPRESA 2
        User::updateOrCreate(
            [
                'email' => 'carlos@elevadoresdelsur.com',
            ],
            [
                'company_id' => $company2->id,
                'name' => 'Carlos',
                'password' => Hash::make('1234'),
                'role' => 'admin',
                'job_type' => null,
            ]
        );


        // TÉCNICO EMPRESA 2
        User::updateOrCreate(
            [
                'email' => 'tecnico@elevadoresdelsur.com',
            ],
            [
                'company_id' => $company2->id,
                'name' => 'Técnico Carlos',
                'password' => Hash::make('1234'),
                'role' => 'technician',
                'job_type' => 'Tecnico',
            ]
        );
    }
}
