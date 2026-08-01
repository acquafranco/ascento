<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                    ->required(),
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
                    ->required(),
                Section::make('WhatsApp Business')
                    ->components([
                        Toggle::make('whatsapp_connected')
                            ->label('Conectado')
                            ->disabled(),

                        TextInput::make('whatsapp_business_id')
                            ->label('Business ID')
                            ->disabled(),

                        TextInput::make('whatsapp_waba_id')
                            ->label('WABA ID')
                            ->disabled(),

                        TextInput::make('whatsapp_phone_number_id')
                            ->label('Phone Number ID')
                            ->disabled(),

                        TextInput::make('whatsapp_access_token')
                            ->label('Access Token')
                            ->password()
                            ->revealable()
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
