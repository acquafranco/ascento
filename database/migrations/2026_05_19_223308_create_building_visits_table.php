<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_visits', function (Blueprint $table) {

            $table->id();

            $table->foreignId('building_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | ORIGEN
            |--------------------------------------------------------------------------
            */

            $table->enum('source', [
                'building',
                'work_order',

            ]);

            $table->foreignId('work_order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | TIPO DE VISITA
            |--------------------------------------------------------------------------
            */

            $table->enum('visit_type', [
                'fixed',
                'work_order',
            ]);

             $table->string('assignment_type')

                ->nullable()

                ->after('visit_type');

            /*
            |--------------------------------------------------------------------------
            | ESTADO
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'done',
                'failed',
            ])->default('done');

            /*
            |--------------------------------------------------------------------------
            | FECHA TEMPLATE
            |--------------------------------------------------------------------------
            */

            $table->integer('month');
            $table->integer('year');
            $table->unique([
                'building_id',
                'user_id',
                'visit_type',
                'month',
                'year'
            ]);
            /*
            |--------------------------------------------------------------------------
            | HORARIOS
            |--------------------------------------------------------------------------
            */

            $table->timestamp('visited_at');

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('finished_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | INFO TRABAJO
            |--------------------------------------------------------------------------
            */

            $table->string('unit')
                ->nullable();

            $table->string('work_type')
                ->nullable();



            $table->text('notes')
                ->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'building_visits'
        );
    }
};
