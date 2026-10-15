<?php

namespace App\Filament\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Schemas\ServiceAssignmentForm;
use App\Models\Building;
use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\ServiceCatalogItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Servicios contratados» de un cliente o edificio: qué contrató, a qué se
 * aplica, cómo se calculó el importe y su estado. Se agregan, editan, pausan
 * y finalizan acá mismo (el mismo formulario que «Servicios»). Nada se borra:
 * finalizar deja la fecha de fin y conserva el historial y los cobros.
 */
class ServicesRelationManager extends RelationManager
{
    use GuardedRelation;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Receivables;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'maintenanceServices';

    protected static ?string $title = 'Servicios contratados';

    public function isReadOnly(): bool
    {
        return ! MaintenanceServiceResource::canCreate();
    }

    /** Cliente y edificio de la ficha (no se eligen en el formulario). */
    private function fixed(): array
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Building ? [(int) $owner->client_id, (int) $owner->id] : [(int) $owner->getKey(), null];
    }

    public function form(Schema $schema): Schema
    {
        [$client, $building] = $this->fixed();

        return $schema->components(ServiceAssignmentForm::components(creating: $schema->getOperation() === 'create', fixedClientId: $client, fixedBuildingId: $building));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['building', 'catalogItem', 'oneTimeReceivable']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('description')->label('Servicio')->wrap()
                    ->description(fn (MaintenanceService $r) => ServiceCatalogItem::categoryLabel($r->category)),
                TextColumn::make('scope')->label('Se aplica a')->state(fn (MaintenanceService $r) => $r->scopeLabel())->wrap(),
                TextColumn::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $r) => $r->amountLabel())
                    ->description(fn (MaintenanceService $r) => $r->pricing_mode ? $r->pricingExplanation() : null)->wrap(),
                MaintenanceServiceResource::oneTimeChargeColumn(),
                TextColumn::make('start_date')->label('Desde')->date('d/m/Y')->toggleable(),
                TextColumn::make('end_date')->label('Hasta')->date('d/m/Y')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => MaintenanceService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(MaintenanceService::STATUSES),
            ])
            ->headerActions([
                CreateAction::make()->label('Agregar servicio')->icon('heroicon-o-plus')
                    ->modalHeading('Agregar servicio')->modalWidth(Width::FourExtraLarge)->createAnother(false)
                    ->using(function (array $data, CreateAction $action): Model {
                        [$client, $building] = $this->fixed();
                        $records = ServiceAssignmentForm::createAll(['client_id' => $client] + ($building ? ['building_id' => $building] : []) + $data, "mountedActions.{$action->getNestingIndex()}.data");

                        return $records[0];
                    })
                    ->successNotificationTitle('Servicio agregado. Los cobros del período se generan solos.'),
            ])
            ->recordActions([
                EditAction::make()->modalWidth(Width::FourExtraLarge)
                    ->using(function (MaintenanceService $record, array $data, EditAction $action): MaintenanceService {
                        $record->update(ServiceAssignmentForm::prepareUpdate($data, $record, "mountedActions.{$action->getNestingIndex()}.data"));

                        return $record;
                    }),
                MaintenanceServiceResource::statusAction(MaintenanceService::PAUSED, 'Pausar', 'heroicon-o-pause', 'warning')
                    ->visible(fn (MaintenanceService $r) => $r->status === MaintenanceService::ACTIVE),
                MaintenanceServiceResource::statusAction(MaintenanceService::ACTIVE, 'Reactivar', 'heroicon-o-play', 'success')
                    ->visible(fn (MaintenanceService $r) => $r->status === MaintenanceService::PAUSED),
                Action::make('finish')->label('Finalizar')->icon('heroicon-o-stop-circle')->color('danger')
                    ->visible(fn (MaintenanceService $r) => $r->status !== MaintenanceService::FINISHED)
                    ->requiresConfirmation()
                    ->modalHeading('Finalizar la contratación')
                    ->modalDescription('Deja de generar cobros desde hoy. No se borra nada: el historial, las visitas y los cobros ya generados se conservan.')
                    ->modalSubmitActionLabel('Finalizar')
                    ->action(function (MaintenanceService $record) {
                        $record->update(['status' => MaintenanceService::FINISHED, 'end_date' => $record->end_date && $record->end_date->lt(today()) ? $record->end_date : today()]);

                        Notification::make()->title('Contratación finalizada')->success()->send();
                    }),
            ])
            // Enlaces solo a lo que el usuario puede abrir (un administrativo con «ver»: la ficha de lectura).
            ->recordUrl(fn (MaintenanceService $r) => MaintenanceServiceResource::canView($r) ? MaintenanceServiceResource::getUrl('view', ['record' => $r]) : null)
            ->emptyStateHeading('Sin servicios contratados')
            ->emptyStateDescription(fn () => $this->getOwnerRecord() instanceof Client
                ? 'Agregá los servicios que contrató este cliente: del catálogo o con un precio acordado.'
                : 'Agregá los servicios de este edificio: del catálogo o con un precio acordado.');
    }
}
