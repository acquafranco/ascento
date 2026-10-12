<?php

namespace App\Filament\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Services\Crm\ClientInteractionService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Historial de contactos dentro de la ficha del cliente (área «Historial de contactos»). */
class InteractionsRelationManager extends RelationManager
{
    use GuardedRelation;
    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'interactions';

    protected static ?string $title = 'Contactos';

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Contacts;
    }

    /**
     * En la ficha (solo lectura) igual se pueden registrar contactos: lo
     * decide el permiso «gestionar» del Historial de contactos (ActionGuard),
     * no el de Clientes.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ClientInteractionResource::fields(withClient: false))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns(ClientInteractionResource::columns(withClient: false))
            ->headerActions([
                CreateAction::make()->label('Registrar contacto')
                    ->using(fn (array $data) => app(ClientInteractionService::class)->record(auth()->user(), [...$data, 'client_id' => $this->getOwnerRecord()->id])),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Model $record, array $data) => app(ClientInteractionService::class)->update(auth()->user(), $record, [...$data, 'client_id' => $this->getOwnerRecord()->id])),
            ]);
    }
}
