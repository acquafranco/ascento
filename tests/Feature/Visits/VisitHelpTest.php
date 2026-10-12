<?php

namespace Tests\Feature\Visits;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\PhotoSections\PhotoSectionResource;
use App\Livewire\AdminOnboarding;
use App\Models\HelpDismissal;
use App\Support\Help\HelpTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ayuda de la Etapa 5: explica asignación masiva, frecuencias, secciones de
 * fotos y progreso, sin reiniciar guías completadas ni volver a mostrar
 * ayudas que el usuario ya descartó (las claves no cambian).
 */
class VisitHelpTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_updated_texts_explain_the_new_flows(): void
    {
        $help = AdminOnboarding::sectionHelp();
        $this->assertStringContainsString('Asignar técnicos', $help['buildings']['body']);
        $this->assertStringContainsString('Frecuencia de visitas', $help['buildings']['body']);
        $this->assertStringContainsString('frecuencia de cobro', $help['buildings']['body']);
        $this->assertStringContainsString('no realizado', HelpTopics::get('agenda_intro')['body']);
        $this->assertStringContainsString('Quincenal', HelpTopics::get('agenda_intro')['body']);
        $this->assertSame('photo_sections_intro', HelpTopics::forRoute('filament.ascensores_app.resources.secciones-de-fotos.index'));
        $this->assertStringContainsString('3 fotos', HelpTopics::get('photo_sections_intro')['body']);
    }

    public function test_dismissed_helps_and_completed_guides_stay_that_way(): void
    {
        $this->withoutVite();
        $a = $this->makeTenant();
        $admin = $a['admin'];
        $admin->forceFill(['onboarding_completed_at' => now()->subMonth(), 'onboarding_step' => 9])->save();
        $admin->dismissHelp('agenda_intro');
        $admin->dismissHelp('buildings_intro');

        $this->actingInPanel($admin);
        $this->get(Agenda::getUrl())->assertOk()->assertDontSee('Ascento arma solo la lista de mantenimientos');
        $this->get(BuildingResource::getUrl())->assertOk();
        // La ayuda nueva sí aparece (nunca se descartó) y no reinicia nada.
        $this->get(PhotoSectionResource::getUrl())->assertOk()->assertSee('Las partes del equipo que el técnico puede fotografiar');

        $admin->refresh();
        $this->assertNotNull($admin->onboarding_completed_at);
        $this->assertSame(9, (int) $admin->onboarding_step);
        $this->assertEqualsCanonicalizing(['agenda_intro', 'buildings_intro'], HelpDismissal::where('user_id', $admin->id)->pluck('key')->all());
    }
}
