<?php

namespace App\Filament\Resources\Claims;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\Claims\Pages\ListClientClaims;
use App\Filament\Resources\Claims\Pages\ViewClientClaim;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ClientClaim;
use App\Models\User;
use App\Services\Claims\ClientClaimService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use App\Support\Plans\PlanGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Reclamos del portal y reportes del QR del edificio (Ascento Conecta), con el
 * mismo seguimiento. Solo los de la empresa
 * actual; los administrativos, con «Órdenes de trabajo» («ver» consulta,
 * «gestionar» cambia estado, asigna, anota, responde y crea la orden). Los
 * técnicos no entran al panel: si hace falta, se crea una orden y la reciben
 * como siempre. Lo creado sigue visible aunque el plan cambie (historial).
 */
class ClientClaimResource extends Resource
{
    use GuardedByPermission;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = ClientClaim::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $navigationLabel = 'Reclamos y reportes';

    protected static ?string $modelLabel = 'reclamo';

    protected static ?string $pluralModelLabel = 'Reclamos y reportes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'reclamos';

    /** En el menú: con el portal o los reportes del QR en el plan, o si la empresa ya tiene alguno (historial). */
    public static function shouldRegisterNavigation(): bool
    {
        $companyId = CompanyContext::currentId();

        return parent::shouldRegisterNavigation() && $companyId
            && (PlanGuard::companyAllows($companyId, PlanFeature::ClientPortal) || PlanGuard::companyAllows($companyId, PlanFeature::QrReports)
                || ClientClaim::where('company_id', $companyId)->exists());
    }

    public static function getNavigationBadge(): ?string
    {
        $companyId = CompanyContext::currentId();
        $new = $companyId ? ClientClaim::where('company_id', $companyId)->where('status', ClientClaim::NEW)->count() : 0;

        return $new ? (string) $new : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Reclamos y reportes recibidos, sin revisar';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reclamo')->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('number')->label('Número')->weight('bold')->formatStateUsing(fn ($state, ClientClaim $r) => $r->reference()),
                TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, ClientClaim $r) => $r->statusLabel())
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'resolved', 'closed' => 'success', 'linked' => 'primary', default => 'info'
                    }),
                TextEntry::make('created_at')->label('Presentado')->dateTime('d/m/Y H:i'),
                TextEntry::make('client.name')->label('Cliente')->placeholder('—'),
                TextEntry::make('place')->label('Edificio y ascensor')->state(fn (ClientClaim $r) => $r->placeLabel()),
                TextEntry::make('category')->label('Categoría')->formatStateUsing(fn ($state, ClientClaim $r) => $r->categoryLabel()),
                TextEntry::make('description')->label('Descripción')->columnSpanFull(),
                TextEntry::make('occurred_at')->label('Cuándo pasó (según el cliente)')->dateTime('d/m/Y H:i')->placeholder('No lo indicó')
                    ->visible(fn (ClientClaim $r) => ! $r->isFromQr()),
                TextEntry::make('origin')->label('Origen')->badge()->state(fn (ClientClaim $r) => $r->originLabel())
                    ->color(fn (ClientClaim $r) => $r->isFromQr() ? 'warning' : 'gray'),
                TextEntry::make('creator.name')->label('Lo presentó')->helperText(fn (ClientClaim $r) => $r->creator?->email)
                    ->placeholder(fn (ClientClaim $r) => $r->isFromQr() ? 'Una persona sin cuenta, desde el QR' : '—'),
                TextEntry::make('contact_phone')->label('Teléfono de contacto')->placeholder(fn (ClientClaim $r) => $r->creator?->phone ?: '—')
                    ->visible(fn (ClientClaim $r) => ! $r->isFromQr()),
                TextEntry::make('visibility')->label('Quién más lo ve')->columnSpanFull()
                    ->state(fn (ClientClaim $r) => collect([
                        $r->isFromQr() ? ($r->shared_with_client ? 'La administración del edificio (en su portal)' : 'Solo tu empresa (no se compartió con la administración)') : 'Quien lo presentó, en su portal',
                        $r->public_on_qr ? 'Su estado aparece en el QR público (sin descripción ni fotos)' : null,
                    ])->filter()->implode(' · ')),
                TextEntry::make('assignee.name')->label('Responsable')->placeholder('Sin asignar'),
                TextEntry::make('workOrder.id')->label('Orden de trabajo')->placeholder('Sin orden')
                    ->formatStateUsing(fn ($state) => 'Orden #'.$state)
                    ->url(fn (ClientClaim $r) => $r->work_order_id ? WorkOrderResource::getUrl('edit', ['record' => $r->work_order_id]) : null),
                ViewEntry::make('photos')->label('Fotos')->columnSpanFull()->view('filament.claims.photos')
                    ->visible(fn (ClientClaim $r) => $r->photos()->exists()),
            ]),
            Section::make('Avisos')->columnSpanFull()
                ->description('A quién se intentó avisar en Ascento. Registrar un aviso no confirma que alguien lo haya visto: «abierto» solo si esa persona lo abrió.')
                ->visible(fn (ClientClaim $r) => $r->deliveries()->exists())
                ->schema([ViewEntry::make('deliveries')->hiddenLabel()->view('filament.claims.deliveries')]),
            Section::make('Historial')->columnSpanFull()->description(ClientClaim::STATUS_NOTE)->schema([
                ViewEntry::make('events')->hiddenLabel()->view('filament.claims.history'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['building', 'elevator', 'client', 'assignee']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°')->formatStateUsing(fn ($state, ClientClaim $r) => $r->reference())->sortable()->weight('bold'),
                TextColumn::make('created_at')->label('Presentado')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('place')->label('Lugar')->state(fn (ClientClaim $r) => $r->placeLabel())->wrap()->description(fn (ClientClaim $r) => $r->client?->name),
                TextColumn::make('category')->label('Categoría')->formatStateUsing(fn ($state, ClientClaim $r) => $r->categoryLabel())->toggleable(),
                TextColumn::make('origin')->label('Origen')->badge()->state(fn (ClientClaim $r) => $r->originLabel())
                    ->color(fn (ClientClaim $r) => $r->isFromQr() ? 'warning' : 'gray')->toggleable(),
                TextColumn::make('assignee.name')->label('Responsable')->placeholder('Sin asignar')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, ClientClaim $r) => $r->statusLabel())
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'resolved', 'closed' => 'success', 'linked' => 'primary', default => 'info'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ClientClaim::STATUSES),
                SelectFilter::make('category')->label('Categoría')->options(ClientClaim::CATEGORIES),
                SelectFilter::make('origin')->label('Origen')->options([ClientClaim::ORIGIN_PORTAL => 'Portal', ClientClaim::ORIGIN_QR => 'QR del edificio']),
            ])
            ->recordUrl(fn (ClientClaim $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([static::statusAction()])
            ->emptyStateHeading('Sin reclamos ni reportes')
            ->emptyStateDescription('Cuando un cliente presente un reclamo desde el portal, o alguien reporte un problema desde el QR de un edificio, aparece acá y se avisa a tu empresa.');
    }

    private static function manageable(ClientClaim $record): bool
    {
        return static::canEdit($record) && Access::can(auth()->user(), PermissionArea::WorkOrders, PermissionArea::MANAGE);
    }

    public static function statusAction(): Action
    {
        return Action::make('changeStatus')->label('Cambiar estado')->icon('heroicon-o-arrow-path')
            ->authorize(fn (ClientClaim $record) => static::manageable($record))
            ->fillForm(fn (ClientClaim $record) => ['status' => $record->status])
            ->modalDescription(ClientClaim::STATUS_NOTE)
            ->schema([
                Select::make('status')->label('Estado')->required()->native(false)->live()
                    // «Asociado a una orden» lo pone «Crear orden de trabajo».
                    ->options(fn (ClientClaim $record) => collect(ClientClaim::STATUSES)->except($record->work_order_id ? [] : [ClientClaim::LINKED])->all())
                    ->helperText('Quien lo presentó (y la administración, si lo compartiste) ve el estado nuevo en su portal.'),
                Textarea::make('reason')->label('¿En qué te basás?')->rows(3)->maxLength(2000)
                    ->helperText('Obligatorio para «Resuelto» o «Cerrado»: queda en el historial con tu nombre y la fecha. Por ejemplo: «Remito 000123: se ajustó la puerta del 3.º».')
                    ->required(fn ($get) => in_array($get('status'), ClientClaim::NEEDS_REASON, true))
                    ->visible(fn ($get) => in_array($get('status'), ClientClaim::NEEDS_REASON, true)),
            ])
            ->action(function (ClientClaim $record, array $data) {
                app(ClientClaimService::class)->changeStatus($record, auth()->user(), $data['status'], $data['reason'] ?? null);
                Notification::make()->title('Estado actualizado')->success()->send();
            });
    }

    /** @return list<Action|ActionGroup> */
    public static function manageActions(): array
    {
        return [
            static::statusAction(),
            Action::make('reply')->label('Responder al cliente')->icon('heroicon-o-chat-bubble-left-right')
                // Un reporte del QR no tiene a quién responder hasta que se comparte con la administración.
                ->visible(fn (ClientClaim $record) => ! $record->isFromQr() || $record->shared_with_client)
                ->authorize(fn (ClientClaim $record) => static::manageable($record))
                ->modalDescription('La respuesta la ve el cliente en su portal y le llega un aviso.')
                ->schema([Textarea::make('body')->label('Respuesta')->required()->rows(4)->maxLength(2000)])
                ->action(function (ClientClaim $record, array $data) {
                    app(ClientClaimService::class)->reply($record, auth()->user(), $data['body']);
                    Notification::make()->title('Respuesta enviada al portal')->success()->send();
                }),
            ActionGroup::make([
                Action::make('note')->label('Nota interna')->icon('heroicon-o-lock-closed')
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->modalDescription('Solo la ve tu empresa: nunca aparece en el portal.')
                    ->schema([Textarea::make('body')->label('Nota')->required()->rows(4)->maxLength(2000)])
                    ->action(function (ClientClaim $record, array $data) {
                        app(ClientClaimService::class)->addNote($record, auth()->user(), $data['body']);
                        Notification::make()->title('Nota guardada')->success()->send();
                    }),
                Action::make('assign')->label('Asignar responsable')->icon('heroicon-o-user')
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->fillForm(fn (ClientClaim $record) => ['user_id' => $record->assigned_to])
                    ->schema([
                        Select::make('user_id')->label('Responsable')->native(false)->placeholder('Sin asignar')
                            ->options(fn (ClientClaim $record) => User::query()->where('company_id', $record->company_id)->whereIn('role', ['admin', User::ROLE_STAFF])
                                ->where('is_super_admin', false)->orderBy('name')->get()
                                ->filter(fn (User $u) => $u->role === 'admin' || Access::isActiveStaff($u))->pluck('name', 'id')),
                    ])
                    ->action(function (ClientClaim $record, array $data) {
                        app(ClientClaimService::class)->assign($record, auth()->user(), isset($data['user_id']) ? (int) $data['user_id'] : null);
                        Notification::make()->title('Responsable actualizado')->success()->send();
                    }),
                Action::make('share')->label('Compartir con la administración')->icon('heroicon-o-eye')
                    ->visible(fn (ClientClaim $record) => $record->isFromQr() && ! $record->shared_with_client)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->requiresConfirmation()
                    ->modalDescription('La administración del edificio lo ve en su portal: categoría, descripción, estado y tus respuestas. Nunca las notas internas ni las fotos. Le llega un aviso.')
                    ->action(function (ClientClaim $record) {
                        app(ClientClaimService::class)->shareWithClient($record, auth()->user(), true);
                        Notification::make()->title('Compartido con la administración')->success()->send();
                    }),
                Action::make('unshare')->label('Dejar de compartir con la administración')->icon('heroicon-o-eye-slash')
                    ->visible(fn (ClientClaim $record) => $record->isFromQr() && $record->shared_with_client)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->action(function (ClientClaim $record) {
                        app(ClientClaimService::class)->shareWithClient($record, auth()->user(), false);
                        Notification::make()->title('Ya no se comparte con la administración')->success()->send();
                    }),
                Action::make('publish')->label('Mostrar su estado en el QR')->icon('heroicon-o-qr-code')
                    ->visible(fn (ClientClaim $record) => ! $record->public_on_qr)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->requiresConfirmation()
                    ->modalDescription('Quien escanee el QR del edificio ve la categoría, la fecha y el estado administrativo, si el QR tiene encendido el bloque «Incidencias». Nunca la descripción, las fotos ni quién lo presentó.')
                    ->action(function (ClientClaim $record) {
                        app(ClientClaimService::class)->publishOnQr($record, auth()->user(), true);
                        Notification::make()->title('Estado publicado en el QR')->success()->send();
                    }),
                Action::make('unpublish')->label('Quitar su estado del QR')->icon('heroicon-o-qr-code')
                    ->visible(fn (ClientClaim $record) => (bool) $record->public_on_qr)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record))
                    ->action(function (ClientClaim $record) {
                        app(ClientClaimService::class)->publishOnQr($record, auth()->user(), false);
                        Notification::make()->title('Estado retirado del QR')->success()->send();
                    }),
                Action::make('workOrder')->label('Crear orden de trabajo')->icon('heroicon-o-wrench-screwdriver')
                    ->visible(fn (ClientClaim $record) => ! $record->work_order_id)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record) && WorkOrderResource::canCreate())
                    ->requiresConfirmation()
                    ->modalDescription('Se crea una orden de tipo «Reclamo» para este edificio, sin técnicos: asignalos en la orden. Queda vinculada, el estado pasa a «Asociado a una orden de trabajo» y no se crea otra. Nada se crea solo: es esta acción.')
                    ->action(function (ClientClaim $record) {
                        $order = app(ClientClaimService::class)->createWorkOrder($record, auth()->user());
                        Notification::make()->title('Orden de trabajo creada')->success()->send();

                        return redirect(WorkOrderResource::getUrl('edit', ['record' => $order->id]));
                    }),
            ])->label('Más')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // solo se crean desde el portal o el QR
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientClaims::route('/'),
            'view' => ViewClientClaim::route('/{record}'),
        ];
    }
}
