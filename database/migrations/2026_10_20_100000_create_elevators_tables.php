<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legajo técnico del ascensor.
 *
 * Hasta ahora el ascensor no era una entidad: el edificio guarda cuántos
 * tiene y reportes / órdenes / presupuestos guardan "Ascensor 1". Se crea
 * una fila por equipo, identificada por (edificio, label) — el mismo valor
 * que ya usan esos registros —, así el historial se arma sin duplicar datos.
 *
 * No destructiva: crea filas para los equipos de los edificios existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elevators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('label');                     // "Ascensor 1", "Montacargas 1"
            $table->string('kind')->default('elevator'); // elevator | freight
            $table->boolean('is_active')->default(true); // el edificio dejó de tenerlo: se conserva el legajo

            // Ficha técnica (todo opcional).
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('capacity_kg')->nullable();
            $table->unsignedSmallInteger('capacity_people')->nullable();
            $table->decimal('speed_ms', 5, 2)->nullable();
            $table->unsignedSmallInteger('stops')->nullable();
            $table->date('installed_at')->nullable();
            $table->string('installer')->nullable();
            $table->string('machine_type')->nullable();  // traction | traction_mrl | hydraulic | other
            $table->string('controller')->nullable();
            $table->string('motor')->nullable();
            $table->string('doors')->nullable();
            $table->string('door_operator')->nullable();
            $table->text('components')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['building_id', 'label']);
            $table->index(['company_id', 'serial_number']);
        });

        Schema::create('elevator_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('elevator_id')->constrained()->cascadeOnDelete();
            $table->string('type');                    // plan | manual | certificate | photo | other
            $table->string('title');
            $table->string('path')->unique();          // disco privado
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->date('expires_at')->nullable();    // certificados
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['elevator_id', 'type']);
        });

        // Un legajo por cada equipo de los edificios existentes.
        DB::table('buildings')->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($buildings) {
            $rows = [];

            foreach ($buildings as $building) {
                foreach ([['Ascensor', 'elevator', (int) $building->elevator_count], ['Montacargas', 'freight', (int) $building->freight_elevator_count]] as [$prefix, $kind, $count]) {
                    for ($i = 1; $i <= $count; $i++) {
                        $rows[] = [
                            'company_id' => $building->company_id,
                            'building_id' => $building->id,
                            'label' => "{$prefix} {$i}",
                            'kind' => $kind,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            DB::table('elevators')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevator_documents');
        Schema::dropIfExists('elevators');
    }
};
