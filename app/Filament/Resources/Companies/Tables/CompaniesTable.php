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
                TextColumn::make('whatsapp_phone_number_id')
                    ->searchable(),
                TextColumn::make('whatsapp_waba_id')
                    ->searchable(),
                TextColumn::make('whatsapp_business_id')
                    ->searchable(),
                IconColumn::make('whatsapp_connected')
                    ->boolean(),
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
                EditAction::make(),
                Action::make('entrar')
                    ->label('Entrar')
                    ->icon('heroicon-o-arrow-right')
                    ->action(function ($record) {
                        session([
                            'selected_company_id' => $record->id,
                        ]);

                        return redirect()->to('/admin');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);


    }
}
