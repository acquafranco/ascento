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

            $table->string('name');

            $table->string('slug')->unique();

            $table->string('email')->nullable();

            $table->string('phone')->nullable();

            $table->string('address')->nullable();

            $table->boolean('is_active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | WhatsApp
            |--------------------------------------------------------------------------
            */

            $table->text('whatsapp_access_token')->nullable();

            $table->string('whatsapp_phone_number_id')->nullable();

            $table->string('whatsapp_waba_id')->nullable();

            $table->string('whatsapp_business_id')->nullable();

            $table->boolean('whatsapp_connected')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
