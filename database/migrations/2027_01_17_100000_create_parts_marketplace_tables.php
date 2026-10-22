<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace B2B de repuestos (primera versión: catálogo y pedidos de
 * cotización, sin cobros dentro de Ascento).
 *
 * - suppliers: cuenta comercial de un proveedor (aprobación del SuperAdmin).
 *   Sus usuarios tienen role = supplier y supplier_id; NUNCA company_id.
 * - supplier_products / supplier_product_photos: catálogo.
 * - marketplace_requests (+ items, events): pedido de cotización de una
 *   empresa de mantenimiento a UN proveedor, con su historial.
 * - marketplace_favorites, marketplace_reports: favoritos y reportes de contenido.
 * - platform_audit_logs: acciones del SuperAdmin que no son de una empresa
 *   (aprobar proveedores, moderar, tarifas comerciales). activity_logs exige empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 190)->unique();
            $table->string('legal_name', 190)->nullable();
            $table->char('country', 2)->default('AR');
            $table->string('tax_id', 25)->nullable();
            $table->text('description')->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('coverage', 255)->nullable();
            $table->json('categories')->nullable();
            $table->json('brands')->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | suspended
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['country', 'tax_id']);
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('category', 40);
            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('part_number', 80)->nullable();
            $table->text('description')->nullable();
            // Compatibilidad DECLARADA por el proveedor (Ascento no la verifica).
            $table->text('compatibility')->nullable();
            $table->string('price_mode', 8)->default('quote'); // fixed | quote
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->default('ARS');
            $table->string('availability', 16)->default('to_order'); // in_stock | to_order | unavailable
            $table->string('status', 12)->default('draft'); // draft | published | paused | retired
            // Moderación: lo oculta el SuperAdmin (el proveedor no lo puede volver a mostrar).
            $table->boolean('hidden_by_admin')->default(false);
            $table->string('hidden_reason', 500)->nullable();
            // Destacado: lo asigna el SuperAdmin (después podrá venderse). No salta la moderación.
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'hidden_by_admin', 'category']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('marketplace_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Lo que el comprador decide compartir con el proveedor para coordinar (nada interno).
            $table->string('contact_name', 120);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40)->nullable();
            $table->string('delivery_area', 160)->nullable();
            $table->text('notes')->nullable();
            // sent → quoted → accepted → completed; o declined (proveedor) / rejected (comprador) / cancelled (comprador)
            $table->string('status', 12)->default('sent');
            $table->text('supplier_note')->nullable();
            $table->decimal('quoted_total', 14, 2)->nullable();
            $table->char('currency', 3)->default('ARS');
            $table->unsignedSmallInteger('quote_valid_days')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['supplier_id', 'status', 'created_at']);
        });

        Schema::create('marketplace_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            // Copia del producto al pedir (si después cambia o se retira, el pedido no cambia).
            $table->string('name', 160);
            $table->string('brand', 80)->nullable();
            $table->string('part_number', 80)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('listed_price', 12, 2)->nullable();
            $table->decimal('quoted_unit_price', 12, 2)->nullable();
        });

        Schema::create('marketplace_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 8); // buyer | supplier | admin
            $table->string('from', 12)->nullable();
            $table->string('to', 12);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('marketplace_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'supplier_product_id']);
        });

        Schema::create('marketplace_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 24);
            $table->text('details')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved | dismissed
            $table->string('resolution', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name', 120);
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 190)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('marketplace_reports');
        Schema::dropIfExists('marketplace_favorites');
        Schema::dropIfExists('marketplace_request_events');
        Schema::dropIfExists('marketplace_request_items');
        Schema::dropIfExists('marketplace_requests');
        Schema::dropIfExists('supplier_product_photos');
        Schema::dropIfExists('supplier_products');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
        Schema::dropIfExists('suppliers');
    }
};
