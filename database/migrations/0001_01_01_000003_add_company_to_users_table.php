<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {


        });
    }

    public function down(): void
    {
        // up() no hace nada (company_id se crea en create_users_table), así
        // que el rollback tampoco: antes borraba users.company_id y dejaba a
        // todos los usuarios sin empresa.
    }
};
