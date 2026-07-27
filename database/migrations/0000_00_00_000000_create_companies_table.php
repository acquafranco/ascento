<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {

            $table->id();


            /*
            |--------------------------------------------------------------------------
            | Identidad de empresa
            |--------------------------------------------------------------------------
            */

            $table->string('name');
            // Nombre interno / comercial corto
            // Ej: Cento Ascensores

            $table->string('business_name')->nullable();
            // Razón social
            // Ej: Cento Servicios S.R.L.

            $table->string('slug')->unique();


            /*
            |--------------------------------------------------------------------------
            | Datos fiscales y contacto
            |--------------------------------------------------------------------------
            */

            $table->string('cuit')->nullable();

            $table->string('tax_condition')
                ->nullable();
            // Responsable inscripto, Monotributo, etc.

            $table->string('email')->nullable();
            // Email empresarial

            $table->string('phone')->nullable();

            $table->string('address')->nullable();

            $table->string('city')->nullable();

            $table->string('province')->nullable();


            /*
            |--------------------------------------------------------------------------
            | Imagen y personalización
            |--------------------------------------------------------------------------
            */

            $table->string('logo')->nullable();

            $table->string('primary_color')
                ->default('#2563eb');


            /*
            |--------------------------------------------------------------------------
            | Estado
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | WhatsApp Business
            |--------------------------------------------------------------------------
            */

            $table->text('whatsapp_access_token')
                ->nullable();

            $table->string('whatsapp_phone_number_id')
                ->nullable();

            $table->string('whatsapp_waba_id')
                ->nullable();

            $table->string('whatsapp_business_id')
                ->nullable();

            $table->boolean('whatsapp_connected')
                ->default(false);


            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
