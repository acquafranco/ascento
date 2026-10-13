<?php

namespace App\Filament\Resources\ParachuteTests\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use App\Services\Parachute\ParachuteService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditParachuteTest extends EditRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ParachuteTests;

    protected static string $resource = ParachuteTestResource::class;

    protected static ?string $title = 'Corregir prueba de paracaídas';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(ParachuteService::class);
        $service->update(auth()->user(), $record, $data);

        foreach (array_values($data['files'] ?? []) as $file) {
            $service->attach(auth()->user(), $record, $file);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return ParachuteTestResource::getUrl('view', ['record' => $this->record]);
    }
}
