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
        Schema::create('building_visit_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('building_visit_id')

                    ->constrained()

                    ->cascadeOnDelete();

                $table->foreignId('user_id')

                    ->constrained()

                    ->cascadeOnDelete();

                $table->enum('role', [

                    'creator',

                    'participant',

                ])->default('participant');

                $table->timestamps();

                $table->unique([

                    'building_visit_id',

                    'user_id',

                ]);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('building_visit_participants');
    }
};
