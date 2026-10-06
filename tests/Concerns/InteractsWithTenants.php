<?php

namespace Tests\Concerns;

use App\Models\Building;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

trait InteractsWithTenants
{
    /**
     * Crea una empresa completa con un admin, un técnico y un edificio
     * asignado al técnico. Devuelve todo en un array para que cada test
     * pueda armar escenarios A vs B sin repetir setup.
     *
     * @return array{company: Company, admin: User, technician: User, building: Building}
     */
    protected function makeTenant(): array
    {
        $company = Company::factory()->create();

        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $technician = User::factory()->technician()->create(['company_id' => $company->id]);

        $building = Building::factory()->create(['company_id' => $company->id]);
        $building->users()->attach($technician->id, ['type' => 'maintenance']);

        return compact('company', 'admin', 'technician', 'building');
    }

    protected function actingInPanel(User $user): static
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        return $this;
    }

    protected function validSignature(): string
    {
        // PNG 1x1 transparente, suficientemente largo para la regla min:100.
        return 'data:image/png;base64,'.str_repeat('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ', 3);
    }
}
