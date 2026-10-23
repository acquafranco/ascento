<?php

namespace App\Support\DeliveryNotes;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\ParachuteTest;
use App\Models\Report;
use App\Support\CompanyLogo;
use App\Support\WorkOrderLabels;
use Carbon\Carbon;

/**
 * Los datos de un remito, armados una sola vez para la web (técnico, portal,
 * enlace público) y el PDF: así nunca dicen cosas distintas.
 *
 * Es lo mismo que el remito mostraba antes (nada se quitó ni cambió de
 * significado) y es todo lo que puede ver el cliente:
 * - SÍ: empresa, número, tipo, cliente, edificio, fecha, período, equipos,
 *   técnico, estado, trabajo realizado, materiales (solo cantidades),
 *   pruebas de paracaídas registradas en este remito (equipo y resultado),
 *   técnicos participantes y firmas.
 * - NUNCA: las observaciones internas de la orden de trabajo (notes), costos
 *   de materiales, datos de contacto del técnico ni acciones de la empresa.
 */
final class DeliveryNoteDocument
{
    /** @return array<string, mixed> */
    public static function for(DeliveryNote $note, bool $pdf = false): array
    {
        $company = $note->company;
        $building = $note->building;
        $order = $note->workOrder;
        $visit = $note->buildingVisit;
        $color = self::color($company?->primary_color);

        $kind = match (true) {
            $order?->isTechnicianInitiated() => ['label' => 'Intervención del técnico: '.WorkOrderLabels::type((string) $order->type), 'icon' => '🛠️', 'tone' => 'order'],
            $order !== null => ['label' => 'Orden de trabajo: '.WorkOrderLabels::type((string) $order->type), 'icon' => '🔧', 'tone' => 'order'],
            $visit?->assignment_type === 'maintenance' => ['label' => 'Mantenimiento mensual', 'icon' => '🔄', 'tone' => 'maintenance'],
            $visit?->assignment_type === 'inspection' => ['label' => 'Inspección', 'icon' => '🔍', 'tone' => 'inspection'],
            default => null,
        };

        // Como antes: los participantes de la orden; si es una visita, los de la visita.
        $participants = collect();
        if ($order) {
            $participants = $order->participants;
        }
        if ($visit) {
            $participants = $visit->participants;
        }

        return [
            'number' => str_pad((string) $note->number, 6, '0', STR_PAD_LEFT),
            'company' => [
                'name' => $company?->name,
                'logo' => self::logo($company, $pdf),
                'color' => $color,
                'on_color' => self::readableOn($color),
            ],
            'kind' => $kind,
            'client' => $building?->client?->name,
            'building' => trim(($building?->name ?? '').' '.($building?->address ?? '')),
            'date' => $note->created_at?->format('d/m/Y'),
            'period' => $note->month && $note->year
                ? ucfirst(Carbon::create()->month($note->month)->locale('es')->monthName)
                : 'Sin período asignado',
            'equipment' => $order
                ? ($order->unit ?? 'Sin equipo asignado')
                : (int) $building?->elevator_count.' Asc. / '.(int) $building?->freight_elevator_count.' Mont.',
            'technician' => $note->user?->name,
            'performed' => (bool) $note->performed,
            'description' => (string) $note->description,
            // Trabajos de la visita (intervenciones), cada uno con su resultado. Sin scopes:
            // el remito también se abre por el enlace público (siempre de la empresa del remito).
            'jobs' => $order ? Report::withoutGlobalScopes()->where('company_id', $note->company_id)->where('work_order_id', $order->id)
                ->where('kind', Report::KIND_INTERVENTION)->orderBy('id')->get()
                ->values()->map(fn (Report $job, int $i) => [
                    'title' => 'Trabajo '.($i + 1).($job->elevator_number ? ' · '.$job->elevator_number : ''),
                    'problem' => (string) $job->description,
                    'done' => (string) $job->work_done,
                    'result' => $job->resultLabel(),
                    'ok' => $job->result === 'resolved',
                ])->all() : [],
            'materials' => $order?->materials?->map(fn ($m) => [
                'name' => $m->stockItem?->name ?? 'Material',
                'quantity' => $m->stockItem?->formatQuantity($m->quantity) ?? (string) $m->quantity,
            ])->values()->all() ?? [],
            // Solo las que se registraron EN este remito (nunca una prevista).
            'parachute' => ParachuteTest::withoutGlobalScopes()->where('company_id', $note->company_id)->where('delivery_note_id', $note->id)
                ->with(['elevator' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'label')])->orderBy('id')->get()
                ->map(fn (ParachuteTest $test) => [
                    'elevator' => $test->elevator?->label ?? 'Ascensor',
                    'result' => ParachuteTest::RESULTS[$test->result] ?? $test->result,
                    'ok' => $test->isSatisfactory(),
                ])->all(),
            'participants' => $participants->map(fn ($p) => [
                'name' => $p->name,
                'responsible' => $p->pivot?->role === 'creator',
            ])->values()->all(),
            'signatures' => [
                ['who' => 'Técnico', 'image' => $note->safeSignature('signature'), 'name' => $note->signature_name ?: null],
                ['who' => 'Cliente', 'image' => $note->safeSignature('client_signature'), 'name' => $note->client_signature_name ?: null],
            ],
        ];
    }

    /** Color de marca válido (#RRGGBB) o el oscuro de Ascento. */
    public static function color(?string $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtoupper($value) : '#11161D';
    }

    /** Texto legible sobre el color de marca (blanco u oscuro, según el contraste). */
    public static function readableOn(string $hex): string
    {
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex, 1), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        return $luminance > 0.45 ? '#11161D' : '#FFFFFF';
    }

    /** Logo: solo del disco público y si es una imagen real (ver CompanyLogo). */
    private static function logo(?Company $company, bool $pdf): ?string
    {
        return $pdf ? CompanyLogo::dataUri($company) : CompanyLogo::url($company);
    }
}
