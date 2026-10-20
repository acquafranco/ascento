<?php

namespace Tests\Feature\Help;

use App\Filament\Pages\HelpCenter;
use App\Http\Controllers\HelpController;
use App\Livewire\AdminOnboarding;
use App\Models\Client;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Support\Help\Content\HelpFaq;
use App\Support\Help\HelpGuides;
use App\Support\Help\HelpSearch;
use App\Support\Help\HelpTopics;
use App\Support\Help\NavigationGuide;
use App\Support\NavigationState;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Menú plegable, introducción por usuario, recorrido del menú y Centro de
 * ayuda: el menú es solo visual (los permisos siguen en el servidor), cada
 * persona tiene su propio estado de ayuda y ve solo artículos que puede usar.
 */
class MenuAndHelpCenterTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->a['company']->refresh();
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, ?array $tenant = null): User
    {
        $tenant ??= $this->a;
        $this->actingAs($tenant['admin']);

        return app(StaffManager::class)->create($tenant['admin'], ['name' => 'Ana Oficina', 'email' => 'ana'.uniqid().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    private function sidebarHrefs(string $html): array
    {
        preg_match('#<nav[^>]*class="fi-sidebar-nav".*?</nav>#s', $html, $nav);
        preg_match_all('#<a[^>]+href="([^"]+)"#', $nav[0] ?? '', $m);

        return array_values(array_unique($m[1]));
    }

    /* ============================ MENÚ ============================ */

    public function test_the_menu_is_grouped_in_collapsible_categories_with_a_per_user_state(): void
    {
        $this->a['admin']->forceFill(['onboarding_skipped_at' => now()])->save();
        $this->inPanel($this->a['admin']);
        $html = $this->get('/admin/dashboard')->assertOk()->getContent();

        foreach (['Gestión', 'Operaciones', 'Comercial', 'Administración'] as $group) {
            $this->assertMatchesRegularExpression('#data-group-label="'.$group.'"[^>]*class="[^"]*fi-collapsible#s', $html, $group);
        }
        // Técnicos y Administrativos, dentro de Administración; «Mi empresa» sigue arriba (el panel abre en ella).
        $this->assertMatchesRegularExpression('#data-group-label="Administración".*?Técnicos.*?Administrativos#s', $html);
        $this->get('/admin')->assertRedirect('/admin/company-settings');

        // La preferencia se guarda con una clave del usuario y el panel, sin su id a la vista.
        $key = NavigationState::storageKey($this->a['admin'], 'ascensores_app');
        $this->assertStringContainsString($key, $html);
        $this->assertStringNotContainsString('ascento.menu.v1.ascensores_app.'.$this->a['admin']->id."'", $html);

        $ana = $this->staff(['tasks' => 'view']);
        $b = $this->makeTenant();
        $this->assertNotSame($key, NavigationState::storageKey($ana, 'ascensores_app'));
        $this->assertNotSame($key, NavigationState::storageKey($b['admin'], 'ascensores_app'));
        $this->assertNotSame($key, NavigationState::storageKey($this->a['admin'], 'otro_panel'));
    }

    public function test_every_menu_link_still_opens_and_hidden_options_stay_protected_on_the_server(): void
    {
        $this->a['admin']->forceFill(['onboarding_skipped_at' => now()])->save();
        $this->inPanel($this->a['admin']);
        $hrefs = $this->sidebarHrefs($this->get('/admin/dashboard')->assertOk()->getContent());
        $this->assertGreaterThan(20, count($hrefs));
        foreach ($hrefs as $href) {
            $status = $this->get($href)->getStatusCode();
            $this->assertContains($status, [200, 302], "{$href} respondió {$status}");
        }

    }

    // Filament arma el menú una vez por proceso: cada perfil, en su propia prueba (como en producción, un pedido por proceso).
    public function test_hidden_options_stay_protected_on_the_server_for_a_restricted_staff_member(): void
    {
        // Un administrativo con solo Tareas: el menú no le muestra Clientes y la URL a mano tampoco abre.
        $ana = $this->staff(['tasks' => 'manage']);
        $this->inPanel($ana);
        $html = $this->get('/admin/tareas')->assertOk()->getContent();
        $staffHrefs = $this->sidebarHrefs($html);
        $this->assertNotContains(url('/admin/clients'), $staffHrefs);
        foreach (['/admin/clients', '/admin/buildings', '/admin/administrativos', '/admin/company-settings', '/admin/subscription', '/admin/pedidos-de-ayuda'] as $url) {
            $this->assertContains($this->get($url)->getStatusCode(), [403, 404], $url);
        }
        foreach ($staffHrefs as $href) {
            $this->assertSame(200, $this->get($href)->getStatusCode(), $href);
        }
    }

    public function test_the_menu_tour_explains_only_what_this_person_sees_and_nothing_is_left_out(): void
    {
        $this->inPanel($this->a['admin']);
        $menu = NavigationGuide::for($this->a['admin']->fresh());
        $explained = collect($menu)->flatMap(fn ($g) => array_column($g['items'], 'url'))->all();
        $navUrls = collect(filament()->getNavigation())->flatMap(fn ($g) => collect($g->getItems())->map(fn ($i) => $i->getUrl()))->all();
        $this->assertEqualsCanonicalizing($navUrls, $explained, 'Toda opción del menú del dueño tiene su explicación.');
        foreach ($menu as $group) {
            $this->assertNotSame('', $group['intro']);
            foreach ($group['items'] as $item) {
                $this->assertNotSame('', $item['body'], $item['label']);
            }
        }

    }

    public function test_the_menu_tour_of_a_restricted_staff_member_only_lists_their_options(): void
    {
        $ana = $this->staff(['tasks' => 'manage']);
        $this->inPanel($ana);
        $labels = collect(NavigationGuide::for($ana->fresh()))->flatMap(fn ($g) => array_column($g['items'], 'label'))->all();
        $this->assertContains('Tareas', $labels);
        foreach (['Clientes', 'Administrativos', 'Mi empresa', 'Cobranzas'] as $hidden) {
            $this->assertNotContains($hidden, $labels, $hidden);
        }
    }

    /* ========================= INTRODUCCIÓN ========================= */

    public function test_a_new_staff_member_gets_the_short_intro_once_and_completing_or_dismissing_ends_it(): void
    {
        $ana = $this->staff(['work_orders' => 'manage']);
        $this->inPanel($ana);
        $this->get('/admin/dashboard')->assertOk()->assertSee('data-autostart="true"', false)->assertSee('data-ascento-help', false);
        // Ver páginas no la marca vista.
        $this->get('/admin/work-orders')->assertOk()->assertSee('data-autostart="true"', false);
        $this->assertNull($ana->fresh()->onboarding_completed_at);

        // Corta: sin los pasos de configuración del dueño.
        $keys = array_column(AdminOnboarding::steps($ana->fresh()), 'key');
        $this->assertSame(['welcome', 'menu', 'help', 'done'], $keys);
        $this->assertNotContains('company', $keys);

        Livewire::test(AdminOnboarding::class)->call('complete');
        $this->assertNotNull($ana->fresh()->onboarding_completed_at);
        $this->get('/admin/dashboard')->assertOk()->assertSee('data-autostart="false"', false);

        // Descartar (Omitir, la X o Esc llaman a skip) tampoco vuelve a abrirla.
        $leo = $this->staff(['work_orders' => 'view']);
        $this->inPanel($leo);
        Livewire::test(AdminOnboarding::class)->call('skip');
        $this->assertNotNull($leo->fresh()->onboarding_skipped_at);
        $this->flushSession();
        $this->actingAs($leo->fresh())->get('/admin/dashboard')->assertOk()->assertSee('data-autostart="false"', false);

        // Volver a abrirla desde «?» no cambia el estado.
        Livewire::test(AdminOnboarding::class)->assertSeeHtml('x-on:click="start(0)" data-ascento-restart')->call('saveStep', 2);
        $this->assertFalse($leo->fresh()->shouldAutoStartOnboarding());
    }

    public function test_help_states_are_independent_per_person_and_company(): void
    {
        $ana = $this->staff(['tasks' => 'view']);
        $leo = $this->staff(['tasks' => 'view']);
        $b = $this->makeTenant();
        $this->inPanel($ana);
        Livewire::test(AdminOnboarding::class)->call('complete');

        $this->assertNotNull($ana->fresh()->onboarding_completed_at);
        $this->assertTrue($leo->fresh()->shouldAutoStartOnboarding());
        $this->assertTrue($this->a['admin']->fresh()->shouldAutoStartOnboarding());
        $this->assertNull($b['admin']->fresh()->onboarding_completed_at);
        $this->assertNull($b['admin']->fresh()->onboarding_skipped_at);

        // La bienvenida del técnico tampoco se comparte.
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh())
            ->post(route('help.technician.welcome', ['company' => $this->a['company']->slug]))->assertRedirect();
        $this->assertTrue($this->a['technician']->fresh()->hasSeenHelp(HelpController::WELCOME_TECHNICIAN));
        $this->assertFalse($other->fresh()->hasSeenHelp(HelpController::WELCOME_TECHNICIAN));
    }

    public function test_technicians_portal_users_and_super_admins_do_not_get_the_panel_intro(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->assertFalse($super->canUseHelpIntro());
        $this->assertFalse($this->a['technician']->canUseHelpIntro());
        $this->inPanel($this->a['technician']);
        Livewire::test(AdminOnboarding::class)->assertForbidden();

        // Un administrativo desactivado tampoco.
        $ana = $this->staff(['tasks' => 'view']);
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->deactivate($this->a['admin'], $ana);
        $this->assertFalse($ana->fresh()->canUseHelpIntro());
    }

    /* ===================== BIENVENIDA DEL TÉCNICO Y EL PORTAL ===================== */

    public function test_the_technician_welcome_shows_once_and_only_explains_the_technician_app(): void
    {
        $slug = $this->a['company']->slug;
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $html = $this->get("/{$slug}/dashboard")->assertOk()->getContent();
        $this->assertStringContainsString('data-help-welcome="technician"', $html);
        $this->assertStringNotContainsString('Administrativos', $html);

        $this->post(route('help.technician.welcome', ['company' => $slug]))->assertRedirect();
        $this->get("/{$slug}/dashboard")->assertOk()->assertDontSee('data-help-welcome="technician"', false);
        // La ayuda sigue disponible a mano, con búsqueda que funciona sin JavaScript.
        $this->get("/{$slug}/ayuda?q=firmar")->assertOk()->assertSee('data-guide="t_visit"', false)->assertDontSee('data-guide="delivery_notes"', false);
        $this->get("/{$slug}/ayuda?q=zzzzzz")->assertOk()->assertSee('data-help-empty', false);
    }

    public function test_the_portal_welcome_shows_once_and_never_mentions_the_company_panel(): void
    {
        $client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);

        $this->flushSession();
        $this->actingAs($portal->fresh());
        $html = $this->get(route('portal.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-help-welcome="portal"', $html);
        $this->post(route('portal.help.welcome'))->assertRedirect();
        $this->get(route('portal.home'))->assertOk()->assertDontSee('data-help-welcome="portal"', false);

        $help = $this->get(route('portal.help'))->assertOk()->getContent();
        foreach (HelpGuides::all() as $g) {
            if ($g['audience'] !== HelpGuides::PORTAL) {
                $this->assertStringNotContainsString('data-guide="'.$g['key'].'"', $help, $g['key']);
            }
        }
        // Un técnico no marca la bienvenida del portal.
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $this->post(route('portal.help.welcome'));
        $this->assertFalse($this->a['technician']->fresh()->hasSeenHelp(HelpController::WELCOME_PORTAL));
    }

    /* ========================= CENTRO DE AYUDA ========================= */

    public function test_search_understands_common_words_synonyms_and_incomplete_words(): void
    {
        $panel = array_values(array_filter(HelpGuides::all(), fn ($g) => $g['audience'] === HelpGuides::PANEL));
        $first = fn (string $q) => HelpSearch::search($panel, $q)[0]['key'] ?? null;

        $this->assertSame('building_create', $first('cómo registro un edificio'));
        $this->assertSame('on_call_replace', $first('reemplazar tecnico guardia'));
        $this->assertSame('rebill', $first('volver a cobrar un cargo anulado'));
        $this->assertSame('passwords', $first('olvidé la contraseña'));
        $this->assertSame('client_create', $first('alta consorcio'));          // sinónimo
        $this->assertContains('delivery_notes', array_column(HelpSearch::search($panel, 'remi'), 'key')); // incompleta
        $this->assertContains('on_call', array_column(HelpSearch::search($panel, 'turnos'), 'key'));       // plural + sinónimo
        $this->assertSame([], HelpSearch::search($panel, 'xqzw'));
        $this->assertSame([], HelpSearch::search($panel, '   '));
    }

    public function test_the_library_is_consistent_every_link_points_to_real_content(): void
    {
        $all = HelpGuides::all();
        $keys = array_column($all, 'key');
        $categories = HelpGuides::categories();
        foreach ($all as $g) {
            $this->assertArrayHasKey($g['category'], $categories, $g['key']);
            $this->assertSame($categories[$g['category']]['audience'], $g['audience'], $g['key']);
            $this->assertNotSame('', trim($g['summary']), $g['key']);
            foreach ($g['related'] as $related) {
                $this->assertContains($related, $keys, "{$g['key']} → {$related}");
            }
            if ($g['image']) {
                $this->assertFileExists(public_path($g['image']['src']), $g['key']);
                $this->assertNotSame('', $g['image']['alt']);
            }
        }
        foreach ($categories as $slug => $category) {
            $this->assertNotEmpty(array_filter($all, fn ($g) => $g['category'] === $slug), "Categoría vacía: {$slug}");
        }
        foreach (HelpFaq::all() as $faq) {
            $this->assertTrue($faq['guide'] === null || in_array($faq['guide'], $keys, true), $faq['key']);
        }
        foreach (HelpTopics::screenGuideMap() as $screen => $guides) {
            foreach ($guides as $guide) {
                $this->assertContains($guide, $keys, "{$screen} → {$guide}");
            }
        }
        foreach (NavigationGuide::explained() as $class) {
            $this->assertTrue(class_exists($class), $class);
        }

        // «Abrir la sección» lleva a pantallas que existen y que el dueño (plan Empresa) abre.
        $this->a['admin']->forceFill(['onboarding_skipped_at' => now()])->save();
        $this->inPanel($this->a['admin']);
        foreach (HelpGuides::for($this->a['admin']->fresh(), HelpGuides::PANEL) as $g) {
            if ($g['open'] && ! $g['super']) {
                $url = HelpGuides::openUrl($g);
                $this->assertNotNull($url, $g['key']);
                $this->assertContains($this->get($url)->getStatusCode(), [200, 302], $g['key']);
            }
        }
    }

    public function test_each_profile_sees_only_its_articles(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($this->a['admin']);
        $owner = array_column(HelpGuides::for($this->a['admin']->fresh(), HelpGuides::PANEL), 'key');
        $this->assertContains('emergency_setup', $owner);
        $this->assertContains('staff_permissions', $owner);
        $this->assertNotContains('sa_companies', $owner);

        $this->inPanel($super);
        $this->assertContains('sa_companies', array_column(HelpGuides::for($super->fresh(), HelpGuides::PANEL), 'key'));

        $ana = $this->staff(['tasks' => 'manage']);
        $this->inPanel($ana);
        $staffKeys = array_column(HelpGuides::for($ana->fresh(), HelpGuides::PANEL), 'key');
        foreach (['emergency_setup', 'staff_permissions', 'export', 'sa_companies', 'receivables', 'on_call_replace'] as $key) {
            $this->assertNotContains($key, $staffKeys, $key);
        }
        // Las preguntas frecuentes siguen las mismas reglas.
        $this->assertNotContains('faq_trial', array_column(HelpGuides::faqFor($ana->fresh(), HelpGuides::PANEL), 'key'));

        // El técnico, solo lo suyo.
        $tech = array_column(HelpGuides::for($this->a['technician']->fresh(), HelpGuides::TECHNICIAN), 'key');
        $this->assertNotEmpty($tech);
        $this->assertSame([], array_values(array_filter($tech, fn ($k) => ! str_starts_with($k, 't_'))));
        $this->assertSame([], HelpGuides::for($this->a['technician']->fresh(), HelpGuides::PANEL));
    }

    public function test_the_help_center_has_categories_faq_contextual_links_and_a_safe_way_back(): void
    {
        $this->a['admin']->forceFill(['onboarding_skipped_at' => now()])->save();
        $this->inPanel($this->a['admin']);
        $html = $this->get('/admin/ayuda?desde=/admin/buildings')->assertOk()->getContent();
        $this->assertStringContainsString('data-help-category="guardias"', $html);
        $this->assertStringContainsString('data-help-faq', $html);
        $this->assertStringContainsString('data-help-back', $html);

        // Nunca vuelve a otra web.
        foreach (['https://otra.web/admin/x', '//otra.web/admin', '/admin//x', '/portal'] as $from) {
            $this->get('/admin/ayuda?desde='.urlencode($from))->assertOk()->assertDontSee('data-help-back', false);
        }

        // Búsqueda sin resultados: estado vacío útil.
        Livewire::test(HelpCenter::class)->set('search', 'qwzxqwzx')->assertSee('No encontramos ayuda');

        // «?» en Guardias ofrece las guías de esa pantalla, con vuelta a la pantalla.
        $guides = array_column(HelpTopics::screenGuides('filament.ascensores_app.resources.guardias.index', $this->a['admin']->fresh()), 'key');
        $this->assertSame(['on_call_assign', 'on_call_replace', 'on_call_query', 'on_call', 'on_call_rotation'], $guides);
        $this->get('/admin/guardias')->assertOk()->assertSee('data-guide-link="on_call_replace"', false);
    }
}
