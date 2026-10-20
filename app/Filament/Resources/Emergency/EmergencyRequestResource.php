<?php

namespace App\Filament\Resources\Emergency;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\Emergency\Pages\ListEmergencyRequests;
use App\Filament\Resources\Emergency\Pages\ViewEmergencyRequest;
use App\Filament\Resources\Emergency\RelationManagers\DeliveriesRelationManager;
use App\Models\EmergencyRequest;
use App\Services\Emergency\EmergencyRequestService;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Pedidos de ayuda por posible atrapamiento llegados desde el QR público.
 * Solo los de la empresa actual; los administrativos, con «Órdenes de trabajo»
 * («ver» para consultar, «gestionar» para cerrar). Los pedidos históricos se
 * siguen viendo aunque el plan cambie o se apague el interruptor.
 */
class EmergencyRequestResource extends Resource
{
    use GuardedByPermission;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = EmergencyRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Pedidos de ayuda (historial)';

    protected static ?string $modelLabel = 'pedido de ayuda';

    protected static ?string $pluralModelLabel = 'Pedidos de ayuda';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'pedidos-de-ayuda';

    protected static ?string $recordTitleAttribute = 'reference';

    /** En el menú: con el plan que lo incluye, o si la empresa ya tiene pedidos (historial). */
    public static function shouldRegisterNavigation(): bool
    {
        $companyId = CompanyContext::currentId();

        // Retirados (Ascento Conecta): en el menú solo si la empresa tiene pedidos registrados antes.
        return parent::shouldRegisterNavigation() && $companyId
            && EmergencyRequest::where('company_id', $companyId)->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $companyId = CompanyContext::currentId();
        $open = $companyId ? EmergencyRequest::where('company_id', $companyId)->open()->count() : 0;

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido')->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('reference')->label('Código')->weight('bold'),
                TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, EmergencyRequest $r) => $r->statusLabel())
                    ->color(fn ($state) => $state === EmergencyRequest::OPEN ? 'danger' : 'gray'),
                TextEntry::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                TextEntry::make('place')->label('Lugar')->state(fn (EmergencyRequest $r) => $r->placeLabel()),
                TextEntry::make('building.address')->label('Dirección')->placeholder('—'),
                TextEntry::make('client.name')->label('Cliente')->placeholder('—'),
                TextEntry::make('repeat_count')->label('Pedidos repetidos')->helperText(fn (EmergencyRequest $r) => $r->last_repeat_at ? 'Último: '.$r->last_repeat_at->format('d/m/Y H:i') : null),
                TextEntry::make('notify_rounds')->label('Rondas de aviso')->helperText(fn (EmergencyRequest $r) => $r->last_notified_at ? 'Última: '.$r->last_notified_at->format('d/m/Y H:i') : null),
                TextEntry::make('recipients_count')->label('Avisados en la primera ronda'),
                TextEntry::make('closed')->label('Cierre')->columnSpanFull()
                    ->state(fn (EmergencyRequest $r) => $r->closed_at ? $r->statusLabel().' · '.$r->closed_at->format('d/m/Y H:i').' · '.($r->closer?->name ?? '—').($r->close_note ? ' · '.$r->close_note : '') : null)
                    ->placeholder('Abierta: nadie la cerró todavía.'),
                TextEntry::make('note')->label('Importante')->columnSpanFull()
                    ->state('Registrado desde el QR público, sin cuenta: no está confirmado que haya alguien atrapado. Los avisos indican lo que Ascento intentó entregar, no que alguien lo haya visto.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['building', 'elevator', 'client']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reference')->label('Código')->searchable()->weight('bold'),
                TextColumn::make('place')->label('Lugar')->state(fn (EmergencyRequest $r) => $r->placeLabel())->wrap()
                    ->description(fn (EmergencyRequest $r) => $r->client?->name),
                TextColumn::make('repeat_count')->label('Repetidos')->alignCenter()->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, EmergencyRequest $r) => $r->statusLabel())
                    ->color(fn ($state) => $state === EmergencyRequest::OPEN ? 'danger' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->label('Estado')->options(EmergencyRequest::STATUSES)])
            ->recordUrl(fn (EmergencyRequest $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([static::closeAction()])
            ->emptyStateHeading('Sin pedidos de ayuda')
            ->emptyStateDescription('Cuando alguien pida ayuda desde el QR de un edificio, aparece acá y se avisa a tu empresa y a la administración del edificio.');
    }

    /** Cerrar el pedido: atendido, falsa alarma o cerrado, con una nota. */
    public static function closeAction(): Action
    {
        return Action::make('close')->label('Cerrar pedido')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (EmergencyRequest $record) => $record->isOpen())
            ->authorize(fn (EmergencyRequest $record) => static::canEdit($record))
            ->modalHeading('Cerrar el pedido de ayuda')
            ->schema([
                Select::make('status')->label('Resultado')->required()->native(false)
                    ->options(['attended' => 'Atendido', 'false_alarm' => 'Falsa alarma', 'closed' => 'Cerrado (otro motivo)']),
                Textarea::make('note')->label('Nota')->rows(2)->maxLength(500)->placeholder('Ej: el técnico liberó a la persona a las 22:40'),
            ])
            ->action(function (EmergencyRequest $record, array $data) {
                app(EmergencyRequestService::class)->close($record, auth()->user(), $data['status'], $data['note'] ?? null);
                Notification::make()->title('Pedido cerrado')->success()->send();
            });
    }

    public static function canCreate(): bool
    {
        return false; // solo se crean desde el QR público
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmergencyRequests::route('/'),
            'view' => ViewEmergencyRequest::route('/{record}'),
        ];
    }
}
