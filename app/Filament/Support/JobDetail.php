<?php

namespace App\Filament\Support;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Services\Visits\WorkPhotoGallery;
use App\Support\WorkOrderLabels;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;

/**
 * Detalle de un trabajo en el panel (Mantenimientos e Inspecciones): los
 * datos del trabajo, sus fotos de antes y después por zona (mantenimiento)
 * o su reporte de inspección, y un enlace al remito. El remito no muestra
 * fotos: se ven acá, en su trabajo.
 */
final class JobDetail
{
    /** Datos del trabajo (el registro es su remito firmado). */
    public static function summary(): Section
    {
        return Section::make('Trabajo')->columns(3)->schema([
            TextEntry::make('building.name')->label('Edificio')
                ->formatStateUsing(fn ($state, DeliveryNote $record) => trim($record->building?->name.' — '.$record->building?->address, ' —')),
            TextEntry::make('building.client.name')->label('Cliente')->placeholder('—'),
            TextEntry::make('origin')->label('Origen')
                ->state(fn (DeliveryNote $record) => $record->work_order_id
                    ? 'Orden de trabajo · '.WorkOrderLabels::type((string) $record->workOrder?->type)
                    : 'Visita del período'),
            TextEntry::make('user.name')->label('Técnico')->placeholder('—'),
            TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            TextEntry::make('period')->label('Período')
                ->state(fn (DeliveryNote $record) => $record->month && $record->year
                    ? ucfirst(Carbon::create((int) $record->year, (int) $record->month, 1)->locale('es')->translatedFormat('F Y'))
                    : '—'),
            TextEntry::make('performed')->label('Estado')->badge()
                ->formatStateUsing(fn ($state) => $state ? 'Realizado' : 'No realizado')
                ->color(fn ($state) => $state ? 'success' : 'danger'),
            TextEntry::make('number')->label('Remito')
                ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 6, '0', STR_PAD_LEFT))
                ->url(fn (DeliveryNote $record) => DeliveryNoteResource::getUrl('view', ['record' => $record]))
                ->color('primary'),
            TextEntry::make('description')->label('Trabajo realizado')->columnSpanFull()->placeholder('—'),
        ]);
    }

    /** Fotos de antes y después del trabajo, por zona (las del remito, nunca en el remito). */
    public static function photos(): Section
    {
        return Section::make('Fotos de antes y después')
            ->visible(fn (DeliveryNote $record) => self::hasPlan($record))
            ->description('Por zona: Antes a la izquierda, Después a la derecha. Uso interno: no se comparten con el cliente.')
            ->schema([
                ViewEntry::make('work_photos')->hiddenLabel()->columnSpanFull()
                    ->view('filament.partials.work-photos')
                    ->viewData(fn (DeliveryNote $record) => [
                        'photos' => app(WorkPhotoGallery::class)->forDeliveryNote($record, auth()->user()),
                        'emptyText' => 'Este trabajo no tiene fotos.',
                    ]),
            ]);
    }

    /** Reporte de la inspección (observaciones y fotos del inspector). */
    public static function inspectionReport(): Section
    {
        return Section::make('Reporte de inspección')
            ->visible(fn (DeliveryNote $record) => self::hasPlan($record))
            ->schema([
                ViewEntry::make('inspection_report')->hiddenLabel()->columnSpanFull()
                    ->view('filament.partials.inspection-report')
                    ->viewData(fn (DeliveryNote $record) => ['report' => self::reportFor($record)]),
            ])
            ->headerActions([
                Action::make('viewReport')->label('Ver reporte')->icon('heroicon-o-document-text')->color('gray')
                    // Solo si quien mira puede abrir el reporte (un administrativo puede ver remitos sin ver reportes).
                    ->visible(fn (DeliveryNote $record) => ($report = self::reportFor($record)) !== null && ReportResource::canView($report))
                    ->url(fn (DeliveryNote $record) => ReportResource::getUrl('view', ['record' => self::reportFor($record)])),
            ]);
    }

    /** Fotos de antes y después de inspecciones del flujo anterior (solo lectura, si hay). */
    public static function legacyInspectionPhotos(): Section
    {
        return Section::make('Fotos cargadas antes del reporte de inspección')
            ->collapsible()->collapsed()
            ->visible(fn (DeliveryNote $record) => self::hasPlan($record) && app(WorkPhotoGallery::class)->forDeliveryNote($record, auth()->user())->isNotEmpty())
            ->schema([
                ViewEntry::make('legacy_photos')->hiddenLabel()->columnSpanFull()
                    ->view('filament.partials.work-photos')
                    ->viewData(fn (DeliveryNote $record) => [
                        'photos' => app(WorkPhotoGallery::class)->forDeliveryNote($record, auth()->user()),
                    ]),
            ]);
    }

    /** Sin el plan Empresa: en lugar de fotos y reportes, el aviso del plan. */
    public static function planLocked(string $title): Section
    {
        return Section::make($title)
            ->visible(fn ($record) => ! self::hasPlan($record))
            ->schema([ViewEntry::make('work_photos_locked')->hiddenLabel()->columnSpanFull()->view('filament.partials.work-photos-locked')]);
    }

    public static function hasPlan($record): bool
    {
        return Company::hasWorkPhotosById($record?->company_id ? (int) $record->company_id : null);
    }

    /** Reporte de inspección de la visita del remito (de la misma empresa). */
    public static function reportFor(DeliveryNote $record): ?Report
    {
        if (! $record->building_visit_id) {
            return null;
        }

        $report = Report::withoutGlobalScopes()
            ->where('company_id', $record->company_id)
            ->where('kind', Report::KIND_INSPECTION)
            ->where('building_visit_id', $record->building_visit_id)
            ->whereNull('deleted_at')
            ->with(['photos', 'user:id,name'])
            ->first();

        return $report && $report->canBeViewedBy(auth()->user()) ? $report : null;
    }
}
