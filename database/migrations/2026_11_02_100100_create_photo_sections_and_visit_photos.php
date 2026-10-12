<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de antes y después de mantenimientos e inspecciones (Etapa 5).
 *
 * - photo_sections: secciones de cada empresa (Pozo, Techo, Sala de
 *   máquinas y las que agregue). No se borran: se desactivan, así las fotos
 *   históricas conservan su sección.
 * - visit_photos: cada foto, con su visita y remito, sección (y el nombre
 *   que tenía al cargarla), ascensor opcional, antes/después, autor, fecha y
 *   observación. Los archivos viven en el disco privado. Mientras el técnico
 *   completa el remito, la foto queda "en preparación" (sin visita) dentro
 *   de un lote del formulario; al firmar se vincula. Las que nunca se
 *   vinculan se borran solas (visit-photos:prune).
 *
 * Solo tablas nuevas: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se puede volver a correr: si se cortó a mitad (MySQL agrega claves e
        // índices en sentencias aparte), las tablas quedaron vacías e
        // incompletas y se recrean; si ya están completas, no se tocan.
        // (Se mira el ÚLTIMO índice de cada tabla: si está, se creó completa.)
        foreach (['visit_photos' => ['attached_at', 'created_at'], 'photo_sections' => ['company_id', 'is_active', 'position']] as $name => $last) {
            if (Schema::hasTable($name) && ! Schema::hasIndex($name, $last) && DB::table($name)->doesntExist()) {
                Schema::drop($name);
            }
        }

        if (! Schema::hasTable('photo_sections')) {
            $this->createPhotoSections();
        }

        if (! Schema::hasTable('visit_photos')) {
            $this->createVisitPhotos();
        }
    }

    private function createPhotoSections(): void
    {
        Schema::create('photo_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active', 'position']);
        });
    }

    private function createVisitPhotos(): void
    {
        Schema::create('visit_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_visit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('photo_section_id')->constrained()->restrictOnDelete();
            $table->string('section_name', 60);
            // Sin clave foránea: los legajos de ascensores nunca se borran (quedan
            // inactivos) y la app valida que el equipo sea del mismo edificio.
            $table->unsignedBigInteger('elevator_id')->nullable()->index();
            $table->string('assignment_type', 20);
            $table->string('stage', 10); // before | after
            $table->uuid('batch');
            $table->string('path')->unique();
            $table->string('thumb_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['batch', 'uploaded_by']);
            $table->index(['building_visit_id', 'photo_section_id']);
            $table->index(['attached_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_photos');
        Schema::dropIfExists('photo_sections');
    }
};
