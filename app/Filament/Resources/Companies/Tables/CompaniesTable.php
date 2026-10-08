<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Company;
use App\Support\SubscriptionStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Empresas (solo SuperAdmin): quién es cada cliente y en qué estado está su
 * suscripción. El cobro lo maneja Mercado Pago; acá solo se ve.
 */
class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading de lo que usan las columnas (evita N+1).
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['latestSubscription'])->withCount(['users', 'buildings']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Empresa')
                    ->description(fn (Company $record) => $record->email)
                    ->searchable(['name', 'business_name', 'email', 'cuit'])
                    ->sortable(),

                TextColumn::make('subscription_status')
                    ->label('Suscripción')
                    ->state(fn (Company $record) => SubscriptionStatus::label($record))
                    ->color(fn (Company $record) => SubscriptionStatus::color($record))
                    ->badge(),

                TextColumn::make('plan_name')
                    ->label('Plan')
                    ->state(fn ($record) => ($record instanceof \App\Models\Company ? $record : $record->company)?->plan()->shortName())
                    ->description(fn ($record) => (($record instanceof \App\Models\Company ? $record->latestSubscription : $record)?->legacy_plan) ? 'Precio anterior conservado' : null),

                TextColumn::make('latestSubscription.current_period_end')
                    ->label('Pagado hasta')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('latestSubscription.next_payment_at')
                    ->label('Próximo cobro')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('trial_ends_at')
                    ->label('Prueba hasta')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->toggleable(),

                TextColumn::make('buildings_count')
                    ->label('Edificios')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Habilitada')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('business_name')->label('Razón social')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cuit')->label('CUIT')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->label('Teléfono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subscription')
                    ->label('Suscripción')
                    ->options(SubscriptionStatus::OPTIONS)
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        // El estado se calcula con reglas de fechas: se filtra en PHP
                        // (son decenas/cientos de empresas, no millones).
                        $ids = Company::query()->with('latestSubscription')->get()
                            ->filter(fn (Company $company) => SubscriptionStatus::key($company) === $data['value'])
                            ->modelKeys();

                        return $query->whereKey($ids);
                    }),

                TrashedFilter::make()->label('Desactivadas'),
            ])
            ->recordActions([
                RestoreAction::make()->label('Reactivar'),

                Action::make('subscription')
                    ->label('Suscripción')
                    ->icon('heroicon-o-credit-card')
                    ->color('gray')
                    ->visible(fn (Company $record) => $record->latestSubscription !== null)
                    ->url(fn (Company $record) => SubscriptionResource::getUrl('view', ['record' => $record->latestSubscription])),

                Action::make('entrar')
                    ->label('Entrar')
                    ->icon('heroicon-o-arrow-right')
                    ->action(function (Company $record) {
                        session(['selected_company_id' => $record->id]);

                        return redirect()->to('/admin');
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Desactivar seleccionadas')
                        ->modalHeading('Desactivar seleccionadas')
                        ->modalSubmitActionLabel('Desactivar'),
                    RestoreBulkAction::make()->label('Reactivar seleccionadas'),
                ]),
            ]);
    }
}
