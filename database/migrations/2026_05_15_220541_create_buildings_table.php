<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       Schema::create('buildings', function (Blueprint $table) {
        $table->id();

        $table->foreignId('client_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->string('name');
        $table->string('address');

        $table->string('client_name')->nullable();
        $table->string('contact_person')->nullable();
        $table->string('phone')->nullable();

        $table->integer('elevator_count')
            ->default(0);

        $table->text('notes')
            ->nullable();

        $table->boolean('is_active')
            ->default(true);

        $table->unsignedInteger('traction_elevator_count')->default(0);

        $table->unsignedInteger('hydraulic_elevator_count')->default(0);

        $table->integer('freight_elevator_count')->default(0);

        $table->timestamps();
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
