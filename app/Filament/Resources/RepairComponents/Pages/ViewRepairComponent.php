<?php

namespace App\Filament\Resources\RepairComponents\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\RepairComponents\RepairComponentResource;
use App\Models\RepairComponent;
use App\Services\Repairs\RepairService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;

class ViewRepairComponent extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Repairs;

    protected static string $resource = RepairComponentResource::class;

    public function getTitle(): string
    {
        return $this->record->componentLabel().' · '.($this->record->building?->name ?? '');
    }

    protected function getHeaderActions(): array
    {
        $is = fn (Get $get, string ...$statuses) => in_array($get('to'), $statuses, true);

        return [
            // Solo a los estados permitidos desde el actual (RepairService lo vuelve a verificar).
            Action::make('changeStatus')
                ->label('Cambiar estado')
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn () => $this->record->nextStatuses() !== [])
                ->modalHeading('Cambiar estado')
                ->modalDescription(fn () => 'Estado actual: '.(RepairComponent::STATUSES[$this->record->status] ?? $this->record->status).'.')
                ->schema([
                    Select::make('to')->label('Nuevo estado')->required()->native(false)->live()
                        ->options(fn () => collect($this->record->nextStatuses())->mapWithKeys(fn ($s) => [$s => RepairComponent::STATUSES[$s]])->all()),
                    DatePicker::make('sent_at')->label('Fecha de envío')->native(false)->displayFormat('d/m/Y')->maxDate(today())
                        ->default(fn () => $this->record->sent_at ?? today())
                        ->visible(fn (Get $get) => $is($get, RepairComponent::SENT))->required(fn (Get $get) => $is($get, RepairComponent::SENT)),
                    TextInput::make('provider')->label('Proveedor o taller')->maxLength(160)->default(fn () => $this->record->provider)
                        ->visible(fn (Get $get) => $is($get, RepairComponent::SENT)),
                    DatePicker::make('expected_return_at')->label('Devolución estimada')->native(false)->displayFormat('d/m/Y')
                        ->default(fn () => $this->record->expected_return_at)
                        ->visible(fn (Get $get) => $is($get, RepairComponent::SENT, RepairComponent::IN_REPAIR, RepairComponent::AWAITING_APPROVAL)),
                    TextInput::make('quote_amount')->label('Presupuesto de reparación')->numeric()->minValue(0)->prefix('$')
                        ->default(fn () => $this->record->quote_amount)
                        ->visible(fn (Get $get) => $is($get, RepairComponent::AWAITING_APPROVAL, RepairComponent::IN_REPAIR)),
                    TextInput::make('final_cost')->label('Costo final')->numeric()->minValue(0)->prefix('$')
                        ->default(fn () => $this->record->final_cost)
                        ->visible(fn (Get $get) => $is($get, RepairComponent::REPAIRED, RepairComponent::RECEIVED)),
                    DatePicker::make('returned_at')->label('Fecha en que se recibió')->native(false)->displayFormat('d/m/Y')->maxDate(today())->default(today())
                        ->visible(fn (Get $get) => $is($get, RepairComponent::RECEIVED))->required(fn (Get $get) => $is($get, RepairComponent::RECEIVED)),
                ])
                ->action(function (array $data) {
                    $to = (string) $data['to'];
                    unset($data['to']);
                    app(RepairService::class)->changeStatus(auth()->user(), $this->record, $to, $data);
                    $this->record->refresh();
                    Notification::make()->title('Estado actualizado: '.RepairComponent::STATUSES[$to])->success()->send();
                }),
            EditAction::make()->label('Corregir'),
        ];
    }
}
