<?php

namespace Tests\Concerns;

use App\Models\Building;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Filament\Facades\Filament;

trait InteractsWithTenants
{
    use MakesSignatures;

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

    /**
     * Pone la empresa en un plan (suscripción manual activa y pagada): por
     * ejemplo «empresa» para las funciones fotográficas.
     */
    protected function giveCompanyPlan(Company $company, string $slug): void
    {
        $plan = SubscriptionPlan::findBySlug($slug);

        Subscription::updateOrCreate(['company_id' => $company->id], [
            'provider' => 'manual', 'plan' => $slug, 'status' => Subscription::ACTIVE,
            'amount' => $plan?->price ?? 0, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $company->forgetPlan();
    }

    protected function actingInPanel(User $user): static
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        return $this;
    }
}
