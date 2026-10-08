<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\PaymentsRelationManager;
use App\Models\Subscription;
use App\Support\SubscriptionStatus;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Suscripciones de todas las empresas (solo SuperAdmin, solo lectura).
 * El estado lo escribe la integración con Mercado Pago; acá se consulta.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Suscripciones';

    protected static ?string $modelLabel = 'Suscripción';

    protected static ?string $pluralModelLabel = 'Suscripciones';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company' => fn ($q) => $q->withTrashed()->with('latestSubscription')]))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->state(fn (Subscription $record) => $record->company ? SubscriptionStatus::label($record->company) : '—')
                    ->color(fn (Subscription $record) => $record->company ? SubscriptionStatus::color($record->company) : 'gray')
                    ->badge(),

                TextColumn::make('amount')
                    ->label('Importe')
                    ->formatStateUsing(fn ($state, Subscription $record) => $state ? '$'.number_format((float) $state, 0, ',', '.').' '.$record->currency : '—'),

                TextColumn::make('current_period_start')
                    ->label('Período desde')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('current_period_end')
                    ->label('Pagado hasta')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('next_payment_at')
                    ->label('Próximo cobro')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('last_payment_at')
                    ->label('Último cobro')
                    ->date('d/m/Y')
                    ->description(fn (Subscription $record) => match ($record->last_payment_status) {
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        null => null,
                        default => 'Pendiente',
                    })
                    ->placeholder('—'),

                TextColumn::make('provider')
                    ->label('Medio')
                    ->formatStateUsing(fn ($state) => $state === 'mercadopago' ? 'Mercado Pago' : 'Manual (histórico)')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('provider_subscription_id')
                    ->label('ID Mercado Pago')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_synced_at')
                    ->label('Sincronizada')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado en Mercado Pago')
                    ->options([
                        Subscription::PENDING => 'Pago sin terminar',
                        Subscription::AUTHORIZED => 'Autorizada',
                        Subscription::PAST_DUE => 'Pago rechazado',
                        Subscription::CANCELED => 'Cancelada',
                        Subscription::PAUSED => 'Pausada',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $date = fn ($state) => $state?->format('d/m/Y') ?? '—';

        return $schema->components([
            Section::make('Empresa')
                ->columns(3)
                ->schema([
                    TextEntry::make('company.name')->label('Empresa'),
                    TextEntry::make('company_status')
                        ->label('Estado')
                        ->state(fn (Subscription $record) => SubscriptionStatus::label($record->company))
                        ->color(fn (Subscription $record) => SubscriptionStatus::color($record->company))
                        ->badge(),
                    TextEntry::make('company.trial_ends_at')->label('Prueba gratis hasta')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('payer_email')->label('Email del pagador')->placeholder('—'),
                    TextEntry::make('company.email')->label('Email de la empresa')->placeholder('—'),
                    TextEntry::make('company.cuit')->label('CUIT')->placeholder('—'),
                ]),

            Section::make('Período y cobros')
                ->columns(3)
                ->schema([
                    TextEntry::make('amount')
                        ->label('Importe mensual')
                        ->formatStateUsing(fn ($state, Subscription $record) => $state ? '$'.number_format((float) $state, 0, ',', '.').' '.$record->currency : '—'),
                    TextEntry::make('current_period_start')->label('Período desde')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('current_period_end')->label('Pagado hasta')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('next_payment_at')->label('Próximo cobro')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('last_payment_at')->label('Último cobro')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('last_payment_status')
                        ->label('Resultado del último cobro')
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'approved' => 'Aprobado', 'rejected' => 'Rechazado', null => '—', default => 'Pendiente'
                        })
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'approved' => 'success', 'rejected' => 'danger', default => 'gray'
                        }),
                    TextEntry::make('authorized_at')->label('Autorizada en Mercado Pago')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('canceled_at')->label('Cancelada')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('created_at')->label('Creada')->date('d/m/Y'),
                ]),

            Section::make('Mercado Pago')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('provider_subscription_id')->label('ID de la suscripción')->copyable()->placeholder('—'),
                    TextEntry::make('external_reference')->label('Referencia')->placeholder('—'),
                    TextEntry::make('status')->label('Estado técnico'),
                    TextEntry::make('last_synced_at')->label('Última sincronización')->since()->placeholder('Nunca'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }
}
