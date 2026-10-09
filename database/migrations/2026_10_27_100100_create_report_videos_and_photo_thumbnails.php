<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Miniaturas de fotos de reportes (las existentes se generan la primera vez
 *   que se piden; no se reprocesan todas acá).
 * - Un video por reporte, disco privado, con estado de procesamiento.
 * - Plan: "report_videos" para Profesional y Empresa (solo suma la clave).
 *
 * No destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_photos', function (Blueprint $table) {
            $table->string('thumb_path')->nullable();
        });

        Schema::create('report_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 50);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration')->nullable();          // segundos (si hay ffprobe)
            $table->string('status', 20)->default('ready');           // pending | processing | ready
            $table->string('processing_note')->nullable();             // motivo legible si no se pudo comprimir
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        DB::table('subscription_plans')->whereIn('slug', ['profesional', 'empresa', 'professional'])->get(['id', 'feature_keys'])
            ->each(fn ($plan) => DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...(json_decode((string) $plan->feature_keys, true) ?: []), 'report_videos']))),
            ]));
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(fn ($plan) => DB::table('subscription_plans')->where('id', $plan->id)->update([
            'feature_keys' => json_encode(array_values(array_diff(json_decode((string) $plan->feature_keys, true) ?: [], ['report_videos']))),
        ]));

        Schema::dropIfExists('report_videos');
        Schema::table('report_photos', fn (Blueprint $table) => $table->dropColumn('thumb_path'));
    }
};
