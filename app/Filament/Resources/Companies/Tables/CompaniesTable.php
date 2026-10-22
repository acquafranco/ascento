<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Resources\Companies\CompanyDeletion;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Company;
use App\Support\Countries;
use App\Support\SubscriptionStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
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
            // Eager loading de lo que usan las columnas (evita N+1). Los edificios se
            // cuentan sin el filtro por empresa elegida (BelongsToCompany): cada fila
            // ya cuenta solo los de su empresa, y con ese filtro todas salvo la
            // elegida con «Entrar» quedaban en 0.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['latestSubscription', 'owner'])
                ->withCount(['users', 'buildings' => fn (Builder $buildings) => $buildings->withoutGlobalScope('company')]))
            ->recordUrl(fn (Company $record) => CompanyResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Empresa')
                    ->description(fn (Company $record) => $record->email)
                    ->searchable(['name', 'business_name', 'email', 'cuit'])
                    ->sortable(),

                // Correo del responsable (quien registró la empresa): es un
                // estado distinto de la cuenta y de la suscripción.
                IconColumn::make('owner_verified')
                    ->label('Correo')
                    ->state(fn (Company $record) => (bool) $record->owner?->hasVerifiedEmail())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Company $record) => $record->owner?->hasVerifiedEmail() ? 'Correo confirmado' : 'Correo sin confirmar'),

                TextColumn::make('subscription_status')
                    ->label('Suscripción')
                    ->state(fn (Company $record) => SubscriptionStatus::label($record))
                    ->color(fn (Company $record) => SubscriptionStatus::color($record))
                    ->badge(),

                TextColumn::make('plan_name')
                    ->label('Plan')
                    ->state(fn ($record) => ($record instanceof Company ? $record : $record->company)?->plan()->shortName())
                    ->description(fn ($record) => (($record instanceof Company ? $record->latestSubscription : $record)?->legacy_plan) ? 'Precio anterior conservado' : null),

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
                TextColumn::make('cuit')->label('Identificación fiscal')->description(fn ($record) => $record->taxIdLabel().' · '.Countries::name($record->country))->toggleable(isToggledHiddenByDefault: true),
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

                SelectFilter::make('email_verification')
                    ->label('Correo del responsable')
                    ->options(['verified' => 'Confirmado', 'unverified' => 'Sin confirmar'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'verified' => $query->whereHas('users', fn ($u) => $u->where('role', 'admin')->whereNotNull('email_verified_at')),
                        'unverified' => $query->whereDoesntHave('users', fn ($u) => $u->where('role', 'admin')->whereNotNull('email_verified_at')),
                        default => $query,
                    }),

                SelectFilter::make('registered')
                    ->label('Alta')
                    ->options(['7' => 'Últimos 7 días', '30' => 'Últimos 30 días'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('created_at', '>=', now()->subDays((int) $data['value'])) : $query),

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

                ViewAction::make()->label('Ficha'),
                EditAction::make(),
                // Borrado definitivo (también de una desactivada), con confirmación explícita.
                CompanyDeletion::action(),
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
