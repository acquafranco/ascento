<?php

namespace App\Services\Activity;

use App\Models\PlatformAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro de las acciones del SuperAdmin que no pertenecen a una empresa:
 * aprobar o suspender proveedores, moderar publicaciones, resolver reportes,
 * tarifas y pedidos comerciales. (ActivityLogger es por empresa.)
 * Solo valores simples y cortos; nunca contraseñas ni rutas de archivos.
 */
final class PlatformAudit
{
    private const SENSITIVE = '/pass|token|secret|remember|api[_-]?key|credential|path/i';

    public static function record(string $action, ?Model $subject = null, array $changes = [], ?string $label = null): void
    {
        $actor = auth()->user();

        if (! $actor?->isSuperAdmin()) {
            return;
        }

        $clean = [];
        foreach ($changes as $key => $value) {
            if (! preg_match(self::SENSITIVE, (string) $key)) {
                $clean[$key] = is_scalar($value) || $value === null ? (is_string($value) ? Str::limit($value, 190) : $value) : Str::limit((string) json_encode($value), 190);
            }
        }

        try {
            PlatformAuditLog::create([
                'user_id' => $actor->id,
                'actor_name' => Str::limit((string) $actor->name, 115, ''),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label !== null ? Str::limit($label, 185, '') : ($subject ? Str::limit((string) ($subject->getAttribute('name') ?? ''), 185, '') : null),
                'changes' => $clean ?: null,
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar una acción de plataforma', ['action' => $action, 'exception' => $e::class]);
        }
    }
}
