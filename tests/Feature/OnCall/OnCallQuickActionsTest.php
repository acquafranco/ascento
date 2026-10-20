<?php

namespace Tests\Feature\OnCall;

use App\Filament\Resources\OnCall\Pages\ListOnCallSchedules;
use App\Filament\Resources\OnCall\Widgets\OnCallOverview;
use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Portada simplificada de «Guardias»: quién está ahora y quién sigue, y las
 * dos acciones frecuentes (asignar y reemplazar) sin entrar a cada guardia.
 * Mismo servicio y mismas reglas: historial, capacidad, permisos y empresa.
 */
class OnCallQuickActionsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 20:00')); // miércoles, dentro de la franja
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->actingInPanel($this->a['admin']);
    }

    private function schedule(string $name = 'Guardia nocturna'): OnCallSchedule
    {
        return OnCallSchedule::create(['name' => $name, 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
    }

    public function test_now_and_next_are_shown_first_with_their_actions(): void
    {
        $night = $this->schedule();
        app(OnCallService::class)->assign($night, $this->a['technician'], Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-15 08:00'), $this->a['admin']);

        Livewire::test(OnCallOverview::class)
            ->assertSeeInOrder(['De guardia ahora', $this->a['technician']->name, 'Reemplazar', 'Después', 'Sin técnico', 'Asignar'])
            // Lo que falta cubrir, a la vista; el calendario y el historial, plegados.
            ->assertSeeHtml('data-on-call-gaps')
            ->assertSeeHtml('<details class="oc-more" data-on-call-calendar>')
            ->assertSeeHtml('<details class="oc-more" data-on-call-history>');
    }

    public function test_assign_from_the_front_page_choosing_the_guard(): void
    {
        $night = $this->schedule();
        $this->schedule('Fines de semana');

        Livewire::test(ListOnCallSchedules::class)
            ->assertActionVisible('assignOnCall')->assertActionVisible('replaceOnCall')
            ->callAction('assignOnCall', data: ['schedule_id' => $night->id, 'user_id' => $this->ana->id, 'starts_at' => '2026-10-15 18:00', 'ends_at' => '2026-10-16 08:00', 'reason' => 'Cubre el jueves'])
            ->assertHasNoActionErrors();

        $shift = OnCallShift::sole();
        $this->assertSame([$night->id, $this->ana->id, 'manual'], [$shift->on_call_schedule_id, $shift->user_id, $shift->origin]);
        $this->assertSame('assigned', OnCallChange::sole()->action);

        // El botón «Asignar» de una guardia sin cobertura abre el formulario con esa guardia.
        Livewire::test(ListOnCallSchedules::class)->dispatch('on-call-assign', schedule: $night->id)
            ->assertActionMounted('assignOnCall')->assertSchemaStateSet(['schedule_id' => $night->id], 'mountedActionSchema0');
    }

    public function test_replace_from_the_front_page_keeps_the_history(): void
    {
        $night = $this->schedule();
        $shift = app(OnCallService::class)->assign($night, $this->a['technician'], Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-15 08:00'), $this->a['admin']);

        Livewire::test(ListOnCallSchedules::class)->dispatch('on-call-replace', shift: $shift->id)
            ->assertActionMounted('replaceOnCall')
            ->setActionData(['user_id' => $this->ana->id, 'reason' => 'Enfermedad'])
            ->callMountedAction()->assertHasNoActionErrors();

        // Lo cubierto hasta ahora sigue a nombre del primero; desde ahora, Ana.
        $this->assertSame(OnCallShift::REPLACED, $shift->fresh()->status);
        $this->assertSame([$this->ana->name], app(OnCallService::class)->shiftsAt($night, now())->map(fn ($s) => $s->user->name)->all());
        $this->assertTrue(OnCallChange::where('action', 'replaced')->exists());
    }

    public function test_viewers_and_other_companies_cannot_use_the_quick_actions(): void
    {
        $night = $this->schedule();
        $foreign = OnCallSchedule::withoutGlobalScopes()->create(['company_id' => $this->b['company']->id, 'name' => 'Ajena', 'days' => [1], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        $foreign->forceFill(['company_id' => $this->b['company']->id])->saveQuietly();

        // Una guardia de otra empresa no aparece ni se puede forzar.
        Livewire::test(ListOnCallSchedules::class)
            ->callAction('assignOnCall', data: ['schedule_id' => $foreign->id, 'user_id' => $this->ana->id, 'starts_at' => '2026-10-15 18:00'])
            ->assertHasActionErrors(['schedule_id']);
        $this->assertSame(0, OnCallShift::withoutGlobalScopes()->count());

        // Quien solo puede ver guardias no tiene las acciones.
        $viewer = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Mira guardias', 'email' => 'mira@demo.test', 'password' => 'clave-segura-123'], ['on_call' => 'view']);
        $this->actingInPanel($viewer->fresh());
        Livewire::test(ListOnCallSchedules::class)->assertActionHidden('assignOnCall')->assertActionHidden('replaceOnCall');
        $this->assertNotNull($night);
    }
}
