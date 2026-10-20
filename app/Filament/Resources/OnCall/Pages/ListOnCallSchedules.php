<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * «Horarios de guardia» (Configuración avanzada de Guardias): todos los
 * horarios, también los que ya no se usan. Cada uno con sus turnos, cambios y
 * trabajos. La portada de Guardias es OnCallHome.
 */
class ListOnCallSchedules extends ListRecords
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected static ?string $title = 'Horarios de guardia';

    protected ?string $subheading = 'Cuándo hace falta alguien de guardia. Para asignar o reemplazar técnicos, volvé a «Guardias».';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Volver a Guardias')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(OnCallScheduleResource::getUrl('index')),
            CreateAction::make()->label('Agregar horario de guardia')->icon('heroicon-o-plus'),
        ];
    }
}
