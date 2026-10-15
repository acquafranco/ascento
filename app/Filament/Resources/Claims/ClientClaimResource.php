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
 * Reclamos que los clientes presentan desde el portal. Solo los de la empresa
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

    protected static ?string $navigationLabel = 'Reclamos de clientes';

    protected static ?string $modelLabel = 'reclamo';

    protected static ?string $pluralModelLabel = 'Reclamos de clientes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'reclamos';

    /** En el menú: con el portal en el plan, o si la empresa ya tiene reclamos (historial). */
    public static function shouldRegisterNavigation(): bool
    {
        $companyId = CompanyContext::currentId();

        return parent::shouldRegisterNavigation() && $companyId
            && (PlanGuard::companyAllows($companyId, PlanFeature::ClientPortal) || ClientClaim::where('company_id', $companyId)->exists());
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
        return 'Reclamos nuevos';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reclamo')->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('number')->label('Número')->weight('bold')->formatStateUsing(fn ($state, ClientClaim $r) => $r->reference()),
                TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, ClientClaim $r) => $r->statusLabel())
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'resolved', 'closed' => 'success', default => 'info'
                    }),
                TextEntry::make('created_at')->label('Presentado')->dateTime('d/m/Y H:i'),
                TextEntry::make('client.name')->label('Cliente')->placeholder('—'),
                TextEntry::make('place')->label('Edificio y ascensor')->state(fn (ClientClaim $r) => $r->placeLabel()),
                TextEntry::make('category')->label('Categoría')->formatStateUsing(fn ($state, ClientClaim $r) => $r->categoryLabel()),
                TextEntry::make('description')->label('Descripción')->columnSpanFull(),
                TextEntry::make('occurred_at')->label('Cuándo pasó (según el cliente)')->dateTime('d/m/Y H:i')->placeholder('No lo indicó'),
                TextEntry::make('creator.name')->label('Lo presentó')->helperText(fn (ClientClaim $r) => $r->creator?->email),
                TextEntry::make('contact_phone')->label('Teléfono de contacto')->placeholder(fn (ClientClaim $r) => $r->creator?->phone ?: '—'),
                TextEntry::make('assignee.name')->label('Responsable')->placeholder('Sin asignar'),
                TextEntry::make('workOrder.id')->label('Orden de trabajo')->placeholder('Sin orden')
                    ->formatStateUsing(fn ($state) => 'Orden #'.$state)
                    ->url(fn (ClientClaim $r) => $r->work_order_id ? WorkOrderResource::getUrl('edit', ['record' => $r->work_order_id]) : null),
                ViewEntry::make('photos')->label('Fotos')->columnSpanFull()->view('filament.claims.photos')
                    ->visible(fn (ClientClaim $r) => $r->photos()->exists()),
            ]),
            Section::make('Historial')->columnSpanFull()->schema([
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
                TextColumn::make('assignee.name')->label('Responsable')->placeholder('Sin asignar')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, ClientClaim $r) => $r->statusLabel())
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'resolved', 'closed' => 'success', default => 'info'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ClientClaim::STATUSES),
                SelectFilter::make('category')->label('Categoría')->options(ClientClaim::CATEGORIES),
            ])
            ->recordUrl(fn (ClientClaim $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([static::statusAction()])
            ->emptyStateHeading('Sin reclamos')
            ->emptyStateDescription('Cuando un cliente presente un reclamo desde el portal, aparece acá y se avisa a tu empresa.');
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
            ->schema([
                Select::make('status')->label('Estado')->options(ClientClaim::STATUSES)->required()->native(false)
                    ->helperText('El cliente ve el estado nuevo en su portal.'),
            ])
            ->action(function (ClientClaim $record, array $data) {
                app(ClientClaimService::class)->changeStatus($record, auth()->user(), $data['status']);
                Notification::make()->title('Estado actualizado')->success()->send();
            });
    }

    /** @return list<Action|ActionGroup> */
    public static function manageActions(): array
    {
        return [
            static::statusAction(),
            Action::make('reply')->label('Responder al cliente')->icon('heroicon-o-chat-bubble-left-right')
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
                Action::make('workOrder')->label('Crear orden de trabajo')->icon('heroicon-o-wrench-screwdriver')
                    ->visible(fn (ClientClaim $record) => ! $record->work_order_id)
                    ->authorize(fn (ClientClaim $record) => static::manageable($record) && WorkOrderResource::canCreate())
                    ->requiresConfirmation()
                    ->modalDescription('Se crea una orden de tipo «Reclamo» para este edificio, sin técnicos: asignalos en la orden. El reclamo queda vinculado y no se crea otra.')
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
        return false; // solo se crean desde el portal
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientClaims::route('/'),
            'view' => ViewClientClaim::route('/{record}'),
        ];
    }
}
