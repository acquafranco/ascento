<?php

namespace App\Filament\Resources\Companies\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Support\CompanyContext;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('business_name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('cuit')
                    ->searchable(),
                TextColumn::make('tax_condition')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('address')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('province')
                    ->searchable(),
                TextColumn::make('logo')
                    ->searchable(),
                TextColumn::make('primary_color')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('whatsapp_connected')
                    ->boolean(),



            TextColumn::make('subscription_status')

                ->label('Suscripción')

                ->state(function ($record) {

                    $status = $record->subscription?->status;

                    return match ($status) {

                        'authorized',

                        'active',

                        'trialing' => 'Activa',

                        'pending' => 'Pendiente',

                        'paused' => 'Pausada',

                        'canceled',

                        'cancelled' => 'Cancelada',

                        default => 'Sin suscripción',

                    };

                })

                ->badge()

                ->color(function ($state) {

                    return match ($state) {

                        'Activa' => 'success',

                        'Pendiente',

                        'Pausada' => 'warning',

                        'Cancelada',

                        'Sin suscripción' => 'danger',

                        default => 'gray',

                    };

                }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('entrar')
                    ->label('Entrar')
                    ->icon('heroicon-o-arrow-right')
                    ->action(function ($record) {
                        session([
                            'selected_company_id' => $record->id,
                        ]);

                        return redirect()->to('/admin');
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);


    }
}
