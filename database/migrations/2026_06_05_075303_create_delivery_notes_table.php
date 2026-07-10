<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {

    $table->id();

    $table->string('number')->unique();

    $table->foreignId('building_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('building_visit_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete();

    $table->foreignId('work_order_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete();

    $table->string('assignment_type')
        ->nullable();

    $table->foreignId('user_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->text('description');

    $table->integer('elevator_quantity')->default(0);

    $table->integer('freight_elevator_quantity')->default(0);

    $table->boolean('performed')
        ->default(true);

    $table->integer('month');

    $table->integer('year');

    $table->string('signature_name');

    $table->longText('signature')->nullable();

    $table->longText('client_signature')->nullable();

    $table->string('client_signature_name')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
