<?php

namespace App\Filament\Resources\ParachuteTests\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use App\Services\Parachute\ParachuteService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class CreateParachuteTest extends CreateRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ParachuteTests;

    protected static string $resource = ParachuteTestResource::class;

    protected static ?string $title = 'Registrar prueba de paracaídas';

    /** Ascensor elegido desde el estado por ascensor (se valida al guardar). */
    #[Url(as: 'ascensor')]
    public ?int $elevator = null;

    protected function afterFill(): void
    {
        if ($this->elevator && array_key_exists($this->elevator, ParachuteTestResource::elevatorOptions())) {
            $this->form->fill(['elevator_id' => $this->elevator, 'performed_on' => today()->toDateString()]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ParachuteService::class)->record(auth()->user(), $data, array_values($data['files'] ?? []));
    }

    protected function getRedirectUrl(): string
    {
        return ParachuteTestResource::getUrl('view', ['record' => $this->record]);
    }
}
