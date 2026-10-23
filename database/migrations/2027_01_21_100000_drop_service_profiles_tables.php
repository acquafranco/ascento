<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Limpieza definitiva de «Profesionales y servicios» (función retirada; la
 * creó 2027_01_20). Solo toca lo que era exclusivo de esa función:
 *
 * - las 7 tablas service_* (perfiles, especialidades, marcas y zonas,
 *   solicitudes, mensajes y reportes);
 * - la columna suppliers.sells_products;
 * - los avisos internos de esas solicitudes y perfiles (por su clave de
 *   deduplicación, que solo usaba esa función) y los registros de auditoría
 *   «marketplace.service_*».
 *
 * Nunca toca proveedores, productos, fotos, pedidos, cotizaciones, Premium,
 * destacados, pagos ni suscripciones. Antes de ejecutarla en producción:
 * copia de seguridad (docs/marketplace-limpieza-profesionales.md).
 *
 * Si hay una cuenta registrada «solo servicios» (sells_products = 0), NO se
 * ejecuta: sin esa columna, esa cuenta pasaría a vender repuestos sin haber
 * cumplido los requisitos del registro. Hay que decidir antes qué hacer con ella.
 *
 * down() recrea las estructuras vacías (y las especialidades iniciales): los
 * datos borrados solo vuelven desde la copia de seguridad.
 */
return new class extends Migration
{
    /** Avisos de la función retirada (MarketplaceNotice; claves que ningún aviso de repuestos usa). */
    public const NOTICE_KEYS = ['mkt:team:svc-%', 'mkt:s%:svc:%', 'mkt:c%:svc:%', 'mkt:sr%'];

    public const TABLES = ['service_profile_reports', 'service_contact_messages', 'service_contact_requests',
        'service_profile_terms', 'service_profile_category', 'service_profiles', 'service_categories'];

    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'sells_products')) {
            $servicesOnly = DB::table('suppliers')->where('sells_products', false)->pluck('id')->all();
            if ($servicesOnly !== []) {
                throw new RuntimeException('Hay proveedores registrados «solo servicios» (ids: '.implode(', ', $servicesOnly).'). Sin la columna sells_products pasarían a vender repuestos sin los requisitos del registro: decidí qué hacer con ellos antes de esta limpieza.');
            }
        }

        DB::table('notifications')->where('type', 'App\\Notifications\\Marketplace\\MarketplaceNotice')
            ->where(function ($q) {
                foreach (self::NOTICE_KEYS as $pattern) {
                    $q->orWhere('dedupe_key', 'like', $pattern);
                }
            })->delete();
        // «marketplace.service_profile.*», «.service_request.*», «.service_report.*», «.service_category.*» (ninguna otra acción empieza así).
        DB::table('platform_audit_logs')->where('action', 'like', 'marketplace.service%')->delete();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('suppliers', 'sells_products')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('sells_products'));
        }
    }

    /** Estructura de 2027_01_20, vacía (los datos solo se recuperan de la copia de seguridad). */
    public function down(): void
    {
        if (! Schema::hasColumn('suppliers', 'sells_products')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->boolean('sells_products')->default(true)->after('status');
            });
        }
        if (Schema::hasTable('service_profiles')) {
            return;
        }

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
            $table->string('kind', 20);
            $table->string('public_name', 160);
            $table->string('headline', 160)->nullable();
            $table->text('description');
            $table->text('experience')->nullable();
            $table->string('modality', 10);
            $table->json('brands')->nullable();
            $table->json('services')->nullable();
            $table->char('country', 2)->default('AR');
            $table->boolean('covers_whole_country')->default(false);
            $table->json('provinces')->nullable();
            $table->string('localities', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('search_text')->nullable();
            $table->string('status', 12)->default('draft');
            $table->string('moderation', 12)->nullable();
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
            $table->string('type', 10);
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
            $table->string('status', 12)->default('open');
            $table->string('closed_by', 12)->nullable();
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
            $table->string('side', 12);
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
            $table->string('status', 12)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamps();
            $table->index(['service_profile_id', 'status']);
        });

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
};
