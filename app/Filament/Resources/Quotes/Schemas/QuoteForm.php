<?php

namespace App\Filament\Resources\Quotes\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Información general')
                    ->description('Datos principales del presupuesto.')
                    ->icon('heroicon-o-building-office')
                    ->schema([

                        Select::make('client_id')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->label('Cliente'),

                        Select::make('building_id')
                            ->relationship(
                                'building',
                                'name',
                                fn ($query, callable $get) =>
                                    $query->where('client_id', $get('client_id'))
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Edificio'),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->label('Título'),

                        Textarea::make('description')
                            ->rows(6)
                            ->columnSpanFull()
                            ->label('Descripción'),

                    ]),

                Section::make('Información comercial')
                    ->description('Estado, prioridad y monto.')
                    ->icon('heroicon-o-banknotes')
                    ->columns(2)
                    ->schema([

                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->required()
                            ->label('Monto'),

                        Select::make('status')
                            ->required()
                            ->default('pending')
                            ->label('Estado')
                            ->options([
                                'pending' => 'Pendiente',
                                'sent' => 'Enviado',
                                'approved' => 'Aprobado',
                                'rejected' => 'Rechazado',
                            ]),

                        Select::make('priority')
                            ->required()
                            ->default('normal')
                            ->label('Prioridad')
                            ->options([
                                'low' => '🟢 Baja',
                                'normal' => '🔵 Normal',
                                'high' => '🟠 Alta',
                                'urgent' => '🔴 Urgente',
                            ]),

                    ]),

            ]);
    }
}
