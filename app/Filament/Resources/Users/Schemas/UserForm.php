<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->dehydrated(true),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->dehydrated(true),

            TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->required()
                ->dehydrated(true)
                ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null),

            Select::make('role')
                ->label('Rol')
                ->options([
                    'admin' => 'Administrador',
                    'user' => 'Usuario',
                ])
                ->required()
                ->default('user')
                ->dehydrated(true),

            Select::make('job_type')
                ->label('Tipo de trabajo')
                ->options([
                    'technician' => 'Técnico',
                    'inspection' => 'Inspección',
                    'maintenance' => 'Mantenimiento',
                    'client' => 'Cliente',
                ])
                ->default('technician')
                ->dehydrated(true),
        ]);
    }
}
