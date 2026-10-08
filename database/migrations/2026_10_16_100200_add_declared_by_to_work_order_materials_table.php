<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién declaró cada material usado en una orden (la oficina o el técnico
 * al firmar el remito). Nullable: los renglones existentes quedan sin dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->foreignId('declared_by')->nullable()->after('unit_cost')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('declared_by');
        });
    }
};
