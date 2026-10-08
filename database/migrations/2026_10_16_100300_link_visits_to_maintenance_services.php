<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relación servicio (contrato) → visitas realizadas (mantenimientos e
 * inspecciones). No mezcla entidades: la visita sigue siendo la visita; solo
 * se agrega a qué contrato corresponde. Nullable y no destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('building_visits', function (Blueprint $table) {
            $table->foreignId('maintenance_service_id')->nullable()->after('work_order_id')
                ->constrained('maintenance_services')->nullOnDelete();
        });

        Schema::table('maintenance_services', function (Blueprint $table) {
            // Equipos del edificio que cubre el contrato ("Ascensor 1", …). Vacío = todos.
            $table->json('units')->nullable()->after('building_id');
        });

        // Visitas ya cargadas: misma regla que al crearlas (servicio activo de
        // la misma empresa que cubre ese edificio y esa fecha; primero los del
        // edificio, después los de todo el cliente; el inicio más reciente).
        $services = DB::table('maintenance_services')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderByRaw('building_id is null')
            ->orderByDesc('start_date')
            ->get();

        foreach ($services as $service) {
            $buildingIds = $service->building_id
                ? [$service->building_id]
                : DB::table('buildings')->where('company_id', $service->company_id)->where('client_id', $service->client_id)->pluck('id')->all();

            DB::table('building_visits')
                ->whereNull('maintenance_service_id')
                ->where('company_id', $service->company_id)
                ->whereIn('building_id', $buildingIds)
                ->whereIn('assignment_type', ['maintenance', 'inspection'])
                ->whereDate('visited_at', '>=', $service->start_date)
                ->when($service->end_date, fn ($q) => $q->whereDate('visited_at', '<=', $service->end_date))
                ->update(['maintenance_service_id' => $service->id]);
        }
    }

    public function down(): void
    {
        Schema::table('building_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_service_id');
        });

        Schema::table('maintenance_services', function (Blueprint $table) {
            $table->dropColumn('units');
        });
    }
};
