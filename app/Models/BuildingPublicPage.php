<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QR público de un edificio (Etapa 7). El enlace usa un token aleatorio,
 * nunca el id; desactivar o regenerar deja sin efecto el anterior. Lo que se
 * publica se elige por bloques (SECTIONS); el contenido lo arma
 * PublicBuildingInfo con una lista cerrada de datos. Se gestiona solo desde
 * PublicQrService.
 */
class BuildingPublicPage extends Model
{
    use BelongsToCompany;

    /** Bloques que el administrador elige publicar (columna => texto). */
    public const SECTIONS = [
        'show_elevators' => 'Ascensores del edificio',
        'show_status' => 'Estado informado de cada ascensor',
        'show_last_maintenance' => 'Último mantenimiento registrado',
        'show_last_inspection' => 'Última inspección registrada',
        'show_safety_tests' => 'Pruebas de paracaídas registradas',
        'show_pending_work' => 'Trabajos pendientes',
        'show_repairs' => 'Componentes en reparación',
        'show_contact' => 'Contacto de la empresa',
        // Pasaporte del ascensor (Ascento Conecta)
        'show_service_history' => 'Historial de servicios registrados (fechas, tipo y número de remito)',
        'show_next_visit' => 'Lo que corresponde este período según la frecuencia de visitas',
        'show_documents' => 'Documentación técnica que publiques (una por una)',
        'show_incidents' => 'Estado de las incidencias que publiques (una por una)',
    ];

    protected $fillable = [];

    protected $hidden = ['token'];

    protected $casts = [
        'is_active' => 'boolean',
        'show_elevators' => 'boolean',
        'show_status' => 'boolean',
        'show_last_maintenance' => 'boolean',
        'show_last_inspection' => 'boolean',
        'show_safety_tests' => 'boolean',
        'show_pending_work' => 'boolean',
        'show_contact' => 'boolean',
        'show_repairs' => 'boolean',
        'show_service_history' => 'boolean',
        'show_next_visit' => 'boolean',
        'show_documents' => 'boolean',
        'show_incidents' => 'boolean',
        'generated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function publicUrl(): string
    {
        return route('public-qr.show', ['token' => $this->token]);
    }
}
