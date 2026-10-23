<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\Resources\Marketplace\Pages\ListServiceContactRequests;
use App\Filament\Resources\Marketplace\Pages\ViewServiceContactRequest;
use App\Models\Marketplace\ServiceContactRequest;
use App\Services\Marketplace\ServiceContacts;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Solicitudes de contacto a profesionales para el SuperAdmin: la lista
 * muestra solo datos administrativos (referencia, partes, estado y fechas).
 * El contenido (necesidad, datos de contacto y mensajes) se abre SOLO con un
 * motivo, que queda en la auditoría, y por 15 minutos en esa sesión.
 */
class ServiceContactRequestResource extends Resource
{
    public const UNLOCK_MINUTES = 15;

    protected static ?string $model = ServiceContactRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Solicitudes a profesionales';

    protected static ?string $modelLabel = 'solicitud';

    protected static ?string $pluralModelLabel = 'Solicitudes a profesionales';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'marketplace/solicitudes-profesionales';

    public static function canAccess(): bool
    {
        return MarketplaceModeration::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function unlockKey(int $id): string
    {
        return 'svc-request-unlocked.'.$id;
    }

    public static function isUnlocked(ServiceContactRequest $record): bool
    {
        return (int) session(self::unlockKey($record->id), 0) > now()->timestamp;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('messages'))
            ->columns([
                TextColumn::make('reference')->label('Referencia')->searchable(),
                TextColumn::make('profile_name')->label('Perfil'),
                TextColumn::make('buyer_name')->label('Empresa')->placeholder('Dada de baja'),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => ServiceContactRequest::STATUSES[$state] ?? $state)->badge(),
                TextColumn::make('messages_count')->label('Mensajes'),
                TextColumn::make('created_at')->label('Creada')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('last_message_at')->label('Último movimiento')->dateTime('d/m/Y H:i'),
            ])
            ->filters([SelectFilter::make('status')->label('Estado')->options(ServiceContactRequest::STATUSES)])
            ->recordActions([self::openAction()]);
    }

    public static function openAction(): Action
    {
        return Action::make('open')->label('Abrir contenido')->icon('heroicon-o-lock-open')->color('warning')
            ->visible(fn () => MarketplaceModeration::allowed())
            ->modalDescription('El contenido es privado entre la empresa y el profesional. Abrilo solo con una necesidad administrativa (reporte, fraude, pedido de una de las partes). El motivo queda en la auditoría.')
            ->schema([Textarea::make('reason')->label('Motivo administrativo')->rows(2)->minLength(10)->maxLength(500)->required()])
            ->action(function (ServiceContactRequest $record, array $data) {
                app(ServiceContacts::class)->adminOpen(auth()->user(), $record, $data['reason']);
                session()->put(self::unlockKey($record->id), now()->addMinutes(self::UNLOCK_MINUTES)->timestamp);

                return redirect(self::getUrl('view', ['record' => $record]));
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceContactRequests::route('/'), 'view' => ViewServiceContactRequest::route('/{record}')];
    }
}
