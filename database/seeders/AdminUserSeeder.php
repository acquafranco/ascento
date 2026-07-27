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
        | EMPRESA 1 - ASCENTO
        |--------------------------------------------------------------------------
        */

        $company1 = Company::updateOrCreate(
            ['slug' => 'ascento'],
            [
                'name' => 'Ascento',
                'email' => 'contacto@ascento.com',
                'phone' => '11 4321-1000',
                'address' => 'Av. Corrientes 1234, CABA',
                'primary_color' => '#0f172a',
                'logo' => null,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'franco@ascento.com'],
            [
                'company_id' => $company1->id,
                'name' => 'Franco',
                'password' => Hash::make('1234'),
                'role' => 'admin',
                'job_type' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'tecnico@ascento.com'],
            [
                'company_id' => $company1->id,
                'name' => 'Técnico Ascento',
                'password' => Hash::make('1234'),
                'role' => 'technician',
                'job_type' => 'Técnico',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | EMPRESA 2 - VERTICAL
        |--------------------------------------------------------------------------
        */

        $company2 = Company::updateOrCreate(
            ['slug' => 'vertical-elevadores'],
            [
                'name' => 'Vertical Elevadores',
                'email' => 'info@vertical.com',
                'phone' => '341 555-0101',
                'address' => 'Rosario, Santa Fe',
                'primary_color' => '#2563eb',
                'logo' => null,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@vertical.com'],
            [
                'company_id' => $company2->id,
                'name' => 'María González',
                'password' => Hash::make('1234'),
                'role' => 'admin',
                'job_type' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'tecnico@vertical.com'],
            [
                'company_id' => $company2->id,
                'name' => 'Juan Pérez',
                'password' => Hash::make('1234'),
                'role' => 'technician',
                'job_type' => 'Técnico',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | EMPRESA 3 - ANDES LIFT
        |--------------------------------------------------------------------------
        */

        $company3 = Company::updateOrCreate(
            ['slug' => 'andes-lift'],
            [
                'name' => 'Andes Lift',
                'email' => 'contacto@andeslift.com',
                'phone' => '261 555-0202',
                'address' => 'Mendoza',
                'primary_color' => '#16a34a',
                'logo' => null,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@andeslift.com'],
            [
                'company_id' => $company3->id,
                'name' => 'Luciano Díaz',
                'password' => Hash::make('1234'),
                'role' => 'admin',
                'job_type' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'tecnico@andeslift.com'],
            [
                'company_id' => $company3->id,
                'name' => 'Pedro Ruiz',
                'password' => Hash::make('1234'),
                'role' => 'technician',
                'job_type' => 'Técnico',
            ]
        );
    }
}
