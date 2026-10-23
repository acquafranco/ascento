<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Marketplace — «Profesionales y servicios» (docs/marketplace-profesionales.md).
 *
 * Solo agrega tablas y una columna con valor por defecto: no cambia ni borra
 * datos existentes. Separado de productos, pedidos y cobros.
 *
 * - suppliers.sells_products: true para todas las cuentas existentes (siguen
 *   igual); false solo para cuentas nuevas que ofrecen SOLO servicios (sin
 *   identificación fiscal obligatoria).
 * - service_categories: especialidades y servicios (las administra el
 *   SuperAdmin; se cargan las iniciales).
 * - service_profiles: un perfil por cuenta de proveedor O por empresa de
 *   mantenimiento (exactamente uno de los dos; lo controla el código).
 * - service_profile_terms: marcas y zonas normalizadas (filtros eficientes).
 * - service_contact_requests / _messages: solicitudes de contacto de una
 *   empresa a un perfil y su conversación. Nunca se borran.
 * - service_profile_reports: reportes de abuso de un perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('sells_products')->default(true)->after('status');
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 90)->unique();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('slug', 190)->unique();
            $table->string('kind', 20); // independent | workshop | service_company
            $table->string('public_name', 160);
            $table->string('headline', 160)->nullable();
            $table->text('description');
            $table->text('experience')->nullable();
            $table->string('modality', 10); // onsite | remote | both
            $table->json('brands')->nullable();
            $table->json('services')->nullable();
            $table->char('country', 2)->default('AR');
            $table->boolean('covers_whole_country')->default(false);
            $table->json('provinces')->nullable();
            $table->string('localities', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('search_text')->nullable();
            $table->string('status', 12)->default('draft'); // draft | published | archived (lo decide el dueño)
            $table->string('moderation', 12)->nullable(); // null | hidden | suspended (lo decide Ascento)
            $table->string('moderation_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'moderation', 'approved_at']);
        });

        Schema::create('service_profile_category', function (Blueprint $table) {
            $table->foreignId('service_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->primary(['service_profile_id', 'service_category_id']);
            $table->index('service_category_id');
        });

        Schema::create('service_profile_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // brand | area
            $table->string('term', 120);
            $table->string('label', 120);
            $table->index(['type', 'term']);
        });

        Schema::create('service_contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('service_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_name', 160)->nullable();
            $table->string('profile_name', 160);
            $table->string('contact_name', 120);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40)->nullable();
            $table->string('subject', 160);
            $table->text('need');
            $table->string('modality', 10)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('status', 12)->default('open'); // open | answered | closed
            $table->string('closed_by', 12)->nullable(); // company | professional | ascento
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();
            $table->index(['service_profile_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('service_contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_contact_request_id')->constrained()->restrictOnDelete();
            $table->string('side', 12); // company | professional
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 160)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::create('service_profile_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 30);
            $table->text('details')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamps();
            $table->index(['service_profile_id', 'status']);
        });

        // Categorías iniciales (tabla nueva: no pisa nada). El SuperAdmin las edita después.
        $now = now();
        $initial = [
            'Ingeniería de ascensores', 'Técnico experto en marcas', 'Controles electrónicos', 'Diagnóstico y reparación de placas',
            'Reparación de variadores', 'Asistencia técnica remota', 'Asistencia técnica presencial', 'Inspecciones y auditorías técnicas',
            'Modernización de ascensores', 'Taller especializado', 'Consultoría para empresas de mantenimiento',
        ];
        DB::table('service_categories')->insert(collect($initial)->values()->map(fn ($name, $i) => [
            'name' => $name, 'slug' => Str::slug($name), 'position' => ($i + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('service_profile_reports');
        Schema::dropIfExists('service_contact_messages');
        Schema::dropIfExists('service_contact_requests');
        Schema::dropIfExists('service_profile_terms');
        Schema::dropIfExists('service_profile_category');
        Schema::dropIfExists('service_profiles');
        Schema::dropIfExists('service_categories');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('sells_products'));
    }
};
