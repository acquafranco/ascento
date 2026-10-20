<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ...static::basicFields(),

            // (Sin interruptor «Activo»: no cambiaba nada. Para que deje de
            // aparecer y se cierre su portal, se desactiva el cliente.)
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ]);
    }

    /**
     * Nombre, tipo y contacto: los mismos campos en el alta del panel y en la
     * configuración inicial guiada (CompanySetupWizard).
     *
     * @return list<Field>
     */
    public static function basicFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->required(),

            Select::make('type')
                ->label('Tipo de cliente')
                ->options(Client::TYPES)
                ->default(Client::DEFAULT_TYPE)
                ->required(),

            TextInput::make('contact_person')
                ->label('Persona de contacto'),

            TextInput::make('phone')
                ->label('Teléfono')
                ->tel(),

            TextInput::make('email')
                ->label('Correo electrónico')
                ->email(),
        ];
    }
}
