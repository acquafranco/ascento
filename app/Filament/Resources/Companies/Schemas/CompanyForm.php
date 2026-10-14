<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('business_name'),
                TextInput::make('slug')
                    ->required()
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->notIn(Company::RESERVED_SLUGS)
                    ->unique(ignoreRecord: true)
                    ->helperText('Minúsculas, números y guiones. Es parte de la dirección de la app de los técnicos.'),
                TextInput::make('cuit'),
                TextInput::make('tax_condition'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('address'),
                TextInput::make('city'),
                TextInput::make('province'),
                TextInput::make('logo'),
                TextInput::make('primary_color')
                    ->required()
                    ->default('#2563eb'),
                Toggle::make('is_active')
                    ->label('Empresa activa')
                    ->helperText('Si se desactiva, nadie de la empresa puede operar (aunque tenga suscripción paga).')
                    ->required(),
            ]);
    }
}
