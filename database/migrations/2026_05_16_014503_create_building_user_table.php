<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('building_user', function (Blueprint $table) {
        $table->id();

        $table->foreignId('building_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        $table->string('type')->nullable();

        $table->timestamps();

        $table->unique(['building_id', 'user_id', 'type']);
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_user');
    }
};
