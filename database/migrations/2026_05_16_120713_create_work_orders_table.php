<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       Schema::create('work_orders', function (Blueprint $table) {

            $table->id();

            $table->foreignId('building_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('type', [
                'inspection',
                'maintenance',
                'claim',
                'installation',
                'modernization',
            ]);

            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'failed',
            ])->default('pending');

            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'urgent',
            ])->default('medium');

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('finished_at')
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->string('unit')
                 ->nullable();



            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
