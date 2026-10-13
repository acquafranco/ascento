<?php

namespace Tests\Feature\Stage9;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Pages\HelpCenter;
use App\Models\Client;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Support\Help\HelpGuides;
use App\Support\Help\HelpTopics;
use App\Support\TechNav;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 9.3: ayuda práctica para el panel, la app del técnico y el portal.
 * Cada persona ve solo las guías que puede usar (plan y permisos), con
 * enlaces desde las ayudas de cada pantalla, y nada entre empresas.
 */
class HelpGuidesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function onPlan(string $slug): void
    {
        $this->giveCompanyPlan($this->a['company'], $slug);
        $this->a['company']->refresh();
    }

    private function staff(array $levels): User
    {
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $this->actingAs($this->a['admin']);

        return app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ana Oficina', 'email' => 'ana'.uniqid().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    private function keys(User $user, string $audience): array
    {
        return array_column(HelpGuides::for($user, $audience), 'key');
    }

    public function test_the_guides_are_well_formed_and_every_screen_help_points_to_a_real_guide(): void
    {
        $all = HelpGuides::all();
        $this->assertSame(count($all), count(array_unique(array_column($all, 'key'))));
        foreach ($all as $g) {
            $this->assertContains($g['audience'], [HelpGuides::PANEL, HelpGuides::TECHNICIAN, HelpGuides::PORTAL], $g['key']);
            $this->assertNotSame('', trim($g['title']), $g['key']);
            $this->assertNotEmpty($g['steps'], $g['key']);
            $this->assertTrue($g['feature'] === null || $g['feature'] instanceof PlanFeature);
            $this->assertTrue($g['area'] === null || $g['area'] instanceof PermissionArea);
        }

        // Cada guía de las ayudas de pantalla existe en el panel (con el dueño en Empresa las ve todas).
        $this->onPlan('empresa');
        $this->inPanel($this->a['admin']);
        $topics = (new \ReflectionClassConstant(HelpTopics::class, 'GUIDES'))->getValue();
        foreach ($topics as $topic => $guide) {
            $this->assertTrue(HelpTopics::exists($topic), $topic);
            $this->assertNotNull(HelpGuides::find($this->a['admin'], HelpGuides::PANEL, $guide), "{$topic} → {$guide}");
        }
    }

    public function test_panel_guides_follow_the_plan(): void
    {
        $this->onPlan('inicial');
        $this->inPanel($this->a['admin']);
        $inicial = $this->keys($this->a['admin'], HelpGuides::PANEL);
        foreach (['start', 'delivery_notes', 'public_qr', 'parachute', 'repairs', 'tasks', 'subscription', 'staff', 'export'] as $key) {
            $this->assertContains($key, $inicial, $key);
        }
        foreach (['quotes', 'portal', 'contacts', 'report_videos', 'work_photos', 'fix_portal_document'] as $key) {
            $this->assertNotContains($key, $inicial, $key);
        }

        $this->onPlan('profesional');
        $this->inPanel($this->a['admin']);
        $profesional = $this->keys($this->a['admin'], HelpGuides::PANEL);
        foreach (['quotes', 'portal', 'contacts', 'report_videos'] as $key) {
            $this->assertContains($key, $profesional, $key);
        }
        $this->assertNotContains('work_photos', $profesional);

        $this->onPlan('empresa');
        $this->inPanel($this->a['admin']);
        $this->assertContains('work_photos', $this->keys($this->a['admin'], HelpGuides::PANEL));
    }

    public function test_staff_only_see_the_guides_of_their_areas(): void
    {
        $this->onPlan('empresa');
        $ana = $this->staff(['tasks' => 'view']);
        $this->inPanel($ana);
        $keys = $this->keys($ana, HelpGuides::PANEL);
        $this->assertContains('tasks', $keys);
        $this->assertContains('notifications', $keys);
        foreach (['quotes', 'repairs', 'elevators', 'start', 'subscription', 'staff', 'export', 'fix_limit'] as $key) {
            $this->assertNotContains($key, $keys, $key);
        }

        // La pantalla «Ayuda»: lo suyo, sin el uso del plan (eso es del dueño).
        $html = $this->get('/admin/ayuda')->assertOk()->getContent();
        $this->assertStringContainsString('data-guide="tasks"', $html);
        $this->assertStringNotContainsString('data-guide="quotes"', $html);
        $this->assertStringNotContainsString('data-help-usage', $html);
        // Un enlace a una guía que no le corresponde no la muestra.
        $this->get('/admin/ayuda?guia=subscription')->assertOk()->assertDontSee('data-guide="subscription"', false);
    }

    public function test_the_owner_help_page_has_search_deep_links_and_plan_usage(): void
    {
        $this->onPlan('inicial');
        $this->inPanel($this->a['admin']);
        $html = $this->get('/admin/ayuda?guia=delivery_notes')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<details class="hg" id="guia-delivery_notes" data-guide="delivery_notes"\s+open#', $html);
        $this->assertStringContainsString('data-help-usage', $html);
        $this->assertStringContainsString('Tu plan: Inicial', $html);
        $this->assertStringContainsString('edificios', $html);

        $page = Livewire::test(HelpCenter::class)->set('search', 'firma');
        $found = collect($page->instance()->guides())->flatten(1)->pluck('key')->all();
        $this->assertContains('delivery_notes', $found);
        $this->assertNotContains('map', $found);
        $page->set('search', '')->set('module', HelpGuides::TROUBLESHOOTING);
        $this->assertSame([HelpGuides::TROUBLESHOOTING], array_keys($page->instance()->guides()));
    }

    public function test_screen_tips_link_to_the_full_guide_also_for_staff(): void
    {
        $this->onPlan('empresa');
        // Mientras corre la guía de bienvenida no se apilan ayudas: el dueño ya la terminó.
        $this->a['admin']->forceFill(['onboarding_skipped_at' => now()])->save();
        $this->inPanel($this->a['admin']);
        $this->get('/admin/tareas')->assertOk()->assertSee('data-help-guide-link', false)->assertSee('guia=tasks', false);

        // Un administrativo vigente también recibe la ayuda de la pantalla (antes, solo el dueño).
        $ana = $this->staff(['tasks' => 'manage']);
        $this->inPanel($ana);
        $this->assertTrue($ana->fresh()->canSeeHelpTips());
        $this->get('/admin/tareas')->assertOk()->assertSee('Pendientes de la oficina con responsable', false)->assertSee('guia=tasks', false);
        // Sin el área, el enlace a esa guía no se ofrece.
        $this->assertNull(HelpTopics::guideUrl('budgets_intro', $ana->fresh()));
        $this->assertNotNull(HelpTopics::guideUrl('tasks_intro', $ana->fresh()));
        // Las ayudas de las secciones nuevas existen.
        foreach (['resources.tareas.index' => 'tasks_intro', 'pages.qr-publicos' => 'public_qr_intro', 'pages.paracaidas' => 'parachute_intro', 'resources.reparaciones.index' => 'repairs_intro', 'resources.administrativos.index' => 'staff_intro', 'resources.contactos.index' => 'contacts_intro'] as $route => $key) {
            $this->assertSame($key, HelpTopics::forRoute('filament.ascensores_app.'.$route), $route);
        }
    }

    public function test_the_technician_has_their_own_help_by_plan(): void
    {
        $slug = $this->a['company']->slug;
        $this->onPlan('inicial');
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $html = $this->get("/{$slug}/ayuda")->assertOk()->getContent();
        foreach (['t_visit', 't_work_order', 't_report', 't_notifications', 't_profile', 't_fix_building'] as $key) {
            $this->assertStringContainsString('data-guide="'.$key.'"', $html, $key);
        }
        $this->assertStringNotContainsString('data-guide="t_photos"', $html);
        $this->assertStringNotContainsString('data-guide="t_video"', $html);
        $this->assertStringNotContainsString('data-guide="quotes"', $html); // nada del panel
        $this->assertContains('Ayuda', array_column(TechNav::get()['more'], 'label'));

        $this->onPlan('empresa');
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $this->get("/{$slug}/ayuda")->assertOk()->assertSee('data-guide="t_photos"', false)->assertSee('data-guide="t_video"', false);
    }

    public function test_the_portal_has_its_own_help(): void
    {
        $this->onPlan('profesional');
        $client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);

        $this->flushSession();
        $this->actingAs($portal->fresh());
        $html = $this->get('/portal/ayuda')->assertOk()->getContent();
        foreach (['p_home', 'p_documents', 'p_notifications', 'p_fix_document'] as $key) {
            $this->assertStringContainsString('data-guide="'.$key.'"', $html, $key);
        }
        $this->assertStringNotContainsString('data-guide="t_visit"', $html);
        $this->assertStringContainsString(route('portal.help'), $this->get(route('portal.home'))->getContent());
    }

    public function test_each_help_is_only_for_its_audience_and_company(): void
    {
        $slug = $this->a['company']->slug;
        $this->onPlan('empresa');

        // Sin sesión: al login.
        $this->get("/{$slug}/ayuda")->assertRedirect();
        $this->get('/portal/ayuda')->assertRedirect();
        $this->get('/admin/ayuda')->assertRedirect();

        // Un administrativo sin ningún permiso no abre la ayuda del panel (como ninguna otra pantalla).
        $nobody = $this->staff([]);
        $this->inPanel($nobody);
        $this->assertFalse(HelpCenter::canAccess());

        // Un administrativo no usa la ayuda de la app del técnico ni el portal.
        $ana = $this->staff(['tasks' => 'view']);
        $this->flushSession();
        $this->actingAs($ana->fresh());
        $this->assertNotSame(200, $this->get("/{$slug}/ayuda")->getStatusCode());
        $this->assertNotSame(200, $this->get('/portal/ayuda')->getStatusCode());

        // Un técnico no entra a la ayuda del panel ni a la de otra empresa.
        $b = $this->makeTenant();
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $this->assertNotSame(200, $this->get('/admin/ayuda')->getStatusCode());
        $this->assertNotSame(200, $this->get('/'.$b['company']->slug.'/ayuda')->getStatusCode());
        $this->assertNotSame(200, $this->get('/portal/ayuda')->getStatusCode());
    }

    public function test_guides_follow_the_effective_plan_and_permissions(): void
    {
        // Con el área de visitas pero sin las fotos en el plan: no hay guía de fotos (y nada de otras áreas).
        $this->onPlan('profesional');
        $ana = $this->staff(['visits' => 'manage']);
        $this->inPanel($ana);
        $keys = $this->keys($ana, HelpGuides::PANEL);
        $this->assertContains('visits', $keys);
        $this->assertNotContains('work_photos', $keys);
        $this->assertNotContains('quotes', $keys);

        $this->onPlan('empresa');
        $this->inPanel($ana);
        $this->assertContains('work_photos', $this->keys($ana, HelpGuides::PANEL));

        // Desactivado por el dueño: sin ayudas de pantalla ni pantalla «Ayuda».
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->deactivate($this->a['admin'], $ana);
        $this->inPanel($ana);
        $this->assertFalse($ana->fresh()->canSeeHelpTips());
        $this->assertFalse(HelpCenter::canAccess());

        // El dueño usa la ayuda del panel, no la de la app del técnico.
        $this->flushSession();
        $this->actingAs($this->a['admin']->fresh());
        $this->assertSame(403, $this->get('/'.$this->a['company']->slug.'/ayuda')->getStatusCode());
        $this->assertSame([], HelpGuides::for($this->a['admin'], HelpGuides::TECHNICIAN));
        $this->assertSame([], HelpGuides::for($this->a['admin'], HelpGuides::PORTAL));
    }
}
