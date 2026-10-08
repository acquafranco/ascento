<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varias fotos por reporte.
 *
 * No destructiva: la foto única de cada reporte (reports.photo) se copia como
 * primera foto y la columna vieja NO se borra (queda para rollback). Ningún
 * archivo se mueve ni se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('path')->unique();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['report_id', 'position']);
        });

        DB::table('reports')
            ->whereNotNull('photo')
            ->where('photo', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($reports) {
                DB::table('report_photos')->insertOrIgnore($reports->map(fn ($report) => [
                    'company_id' => $report->company_id,
                    'report_id' => $report->id,
                    'path' => $report->photo,
                    'position' => 0,
                    'created_at' => $report->created_at,
                    'updated_at' => $report->updated_at,
                ])->all());
            });
    }

    public function down(): void
    {
        // Antes de borrar la tabla, los reportes nuevos (sin columna vieja)
        // recuperan su primera foto en reports.photo. Los archivos quedan.
        DB::table('report_photos')
            ->orderBy('report_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['report_id', 'path'])
            ->unique('report_id')
            ->each(fn ($photo) => DB::table('reports')
                ->where('id', $photo->report_id)
                ->whereNull('photo')
                ->update(['photo' => $photo->path]));

        Schema::dropIfExists('report_photos');
    }
};
