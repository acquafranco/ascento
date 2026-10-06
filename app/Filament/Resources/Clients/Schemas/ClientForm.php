<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            TextInput::make('name')
                ->label('Nombre')
                ->required(),

            Select::make('type')
                ->label('Tipo de cliente')
                ->options([
                    'hospital' => 'Hospital',
                    'consorcio' => 'Consorcio',
                    'empresa' => 'Empresa',
                    'particular' => 'Particular',
                ])
                ->default('consorcio')
                ->required(),

            TextInput::make('contact_person')
                ->label('Persona de contacto'),

            TextInput::make('phone')
                ->label('Teléfono')
                ->tel(),

            TextInput::make('email')
                ->label('Correo electrónico')
                ->email(),

            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('Activo')
                ->default(true),
        ]);
    }
}
