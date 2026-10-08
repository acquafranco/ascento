<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuestos con ítems, validez, condiciones y observaciones.
 *
 * No destructiva: cada presupuesto existente pasa a tener UN ítem con su
 * título y su importe (el total no cambia y la columna `amount` se conserva).
 * El estado "pending" pasa a "draft" (borrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('concept');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->date('issued_at')->nullable()->after('status');
            $table->date('valid_until')->nullable()->after('issued_at');
            $table->text('conditions')->nullable()->after('description');
            $table->text('notes')->nullable()->after('conditions');
            $table->timestamp('voided_at')->nullable()->after('valid_until');
        });

        DB::table('quotes')->orderBy('id')->chunkById(500, function ($quotes) {
            DB::table('quote_items')->insert($quotes->map(fn ($quote) => [
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'position' => 0,
                'concept' => $quote->title,
                'quantity' => 1,
                'unit_price' => $quote->amount,
                'subtotal' => $quote->amount,
                'created_at' => $quote->created_at,
                'updated_at' => $quote->updated_at,
            ])->all());

            foreach ($quotes as $quote) {
                DB::table('quotes')->where('id', $quote->id)->update([
                    'issued_at' => substr((string) $quote->created_at, 0, 10) ?: null,
                ]);
            }
        });

        DB::table('quotes')->where('status', 'pending')->update(['status' => 'draft']);

        // Presupuestos sin cliente: el cliente es el del edificio (mismo dato,
        // así se pueden abrir en el formulario nuevo, que lo pide).
        DB::table('quotes')->whereNull('client_id')->orderBy('id')->each(function ($quote) {
            $clientId = DB::table('buildings')->where('id', $quote->building_id)->where('company_id', $quote->company_id)->value('client_id');

            if ($clientId) {
                DB::table('quotes')->where('id', $quote->id)->update(['client_id' => $clientId]);
            }
        });
    }

    public function down(): void
    {
        // El código anterior solo conoce pending/sent/approved/rejected.
        DB::table('quotes')->where('status', 'draft')->update(['status' => 'pending']);
        DB::table('quotes')->where('status', 'void')->update(['status' => 'rejected']);

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['issued_at', 'valid_until', 'conditions', 'notes', 'voided_at']);
        });

        // `amount` (el total) se conserva: no se pierde ningún importe.
        Schema::dropIfExists('quote_items');
    }
};
