<?php

namespace App\Filament\Resources\DeliveryNotes;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteForm;
use App\Filament\Resources\DeliveryNotes\Tables\DeliveryNotesTable;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\ClientSharing;
use App\Models\DeliveryNote;
use App\Services\WorkOrders\AttendanceTimes;
use App\Support\DeliveryNotes\DeliveryNoteShare;
use App\Support\WorkOrderLabels;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeliveryNoteResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Documents;
    }

    protected static ?string $model = DeliveryNote::class;

    protected static string|BackedEnum|null $navigationIcon =

        Heroicon::OutlinedDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Remitos';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return 'Remitos';
    }

    public static function getModelLabel(): string
    {
        return 'Remito';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Remitos';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryNoteForm::configure($schema);
    }

    /**
     * El remito completo (el mismo documento que ve el cliente, con firmas)
     * y debajo los datos internos de la empresa.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('document')->hiddenLabel()->columnSpanFull()->view('filament.delivery-notes.document'),

            Section::make('Datos internos')
                ->description('Solo los ve tu empresa: no salen en el remito del cliente.')
                ->columnSpanFull()
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('origin')->label('Origen')
                        ->state(fn (DeliveryNote $record) => match (true) {
                            $record->work_order_id !== null => 'Orden de trabajo #'.$record->work_order_id,
                            $record->building_visit_id !== null => 'Visita de '.WorkOrderLabels::type((string) $record->buildingVisit?->assignment_type),
                            default => 'Carga directa',
                        })
                        ->url(fn (DeliveryNote $record) => $record->work_order_id && WorkOrderResource::canAccess()
                            ? WorkOrderResource::getUrl('edit', ['record' => $record->work_order_id]) : null),
                    // El trabajo se originó en una guardia (no sale en el remito del cliente).
                    TextEntry::make('on_call_origin')->label('Guardia')
                        ->state(fn (DeliveryNote $record) => ($shift = $record->workOrder?->onCallShift)
                            ? $shift->schedule?->name.' · de guardia: '.$shift->user?->name.' ('.$shift->periodLabel().')'
                            : null)
                        ->visible(fn (DeliveryNote $record) => (bool) $record->workOrder?->on_call_shift_id),
                    TextEntry::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                    // Visita técnica: llegada del técnico al edificio, o por qué se firmó sin ella.
                    TextEntry::make('site_visit')->label('Llegada al edificio')
                        ->state(fn (DeliveryNote $record) => match (true) {
                            (bool) $record->buildingVisit?->siteVisit => AttendanceTimes::time($record->buildingVisit->siteVisit->arrived_at)
                                .' · salida '.AttendanceTimes::time($record->buildingVisit->siteVisit->left_at),
                            filled($record->buildingVisit?->arrival_exception) => 'Sin llegada registrada: '.$record->buildingVisit->arrival_exception,
                            default => null,
                        })
                        ->visible(fn (DeliveryNote $record) => (bool) ($record->buildingVisit?->site_visit_id || filled($record->buildingVisit?->arrival_exception))),
                    TextEntry::make('portal')->label('Portal del cliente')->badge()
                        ->state(fn (DeliveryNote $record) => $record->shared_with_client ? 'Compartido' : 'No compartido')
                        ->color(fn (string $state) => $state === 'Compartido' ? 'success' : 'gray')
                        ->visible(fn () => ClientSharing::enabled()),
                    TextEntry::make('share_link')->label('Enlace para compartir')
                        ->state(fn (DeliveryNote $record) => DeliveryNoteShare::canShare($record) ? 'Disponible' : 'Se crea al compartir')
                        ->helperText('Quien tenga el enlace ve el remito (sin datos internos) y descarga su PDF.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return DeliveryNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryNotes::route('/'),
            'view' => ViewDeliveryNote::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user->isSuperAdmin()) {

            $companyId = session('selected_company_id');

            if ($companyId) {
                return $query->where('company_id', $companyId);
            }

            return $query->whereRaw('1 = 0');
        }

        return $query->where(
            'company_id',
            $user->company_id
        );
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    /**
     * Los remitos (y por lo tanto mantenimientos e inspecciones) son
     * historial firmado: no se eliminan. El modelo también lo impide.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
