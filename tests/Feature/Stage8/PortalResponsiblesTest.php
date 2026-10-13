<?php

namespace Tests\Feature\Stage8;

use App\Models\Building;
use App\Models\Client;
use App\Models\PortalMembership;
use App\Models\User;
use App\Services\Technicians\TechnicianPhotoService;
use App\Support\Portal\PortalResponsibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 8.3: responsables visibles en el portal del cliente. Solo los
 * técnicos asignados a SUS edificios autorizados de la empresa activa; foto,
 * nombre, rol y edificios; el contacto es el de la empresa (nunca datos
 * personales del técnico); solo lectura; nada entre empresas.
 */
class PortalResponsiblesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    private Building $mine;

    private Building $notAuthorized;

    private Building $otherClient;

    private User $portal;

    private User $both;

    private User $onlyOther;

    private User $unassigned;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->a['company']->update(['name' => 'Ascensores Norte', 'phone' => '011 4000-1000', 'email' => 'contacto@norte.test']);
        $companyId = $this->a['company']->id;

        $this->client = Client::factory()->create(['company_id' => $companyId, 'name' => 'Consorcio Uno']);
        $otherClient = Client::factory()->create(['company_id' => $companyId, 'name' => 'Consorcio Dos']);
        $this->mine = Building::factory()->create(['company_id' => $companyId, 'client_id' => $this->client->id, 'name' => 'Torre Autorizada']);
        $this->notAuthorized = Building::factory()->create(['company_id' => $companyId, 'client_id' => $this->client->id, 'name' => 'Torre Sin Permiso']);
        $this->otherClient = Building::factory()->create(['company_id' => $companyId, 'client_id' => $otherClient->id, 'name' => 'Torre De Otro Cliente']);

        $tech = fn (string $name, string $phone) => User::factory()->technician()->create(['company_id' => $companyId, 'name' => $name, 'email' => str($name)->slug().'@personal.test', 'phone' => $phone]);
        $this->both = $tech('Lucas Fernández', '11 5555-0001');
        $this->onlyOther = $tech('Pedro Ajeno', '11 5555-0002');
        $this->unassigned = $tech('Sofía Sin Edificios', '11 5555-0003');
        $this->mine->users()->attach($this->both->id, ['type' => 'maintenance']);
        $this->mine->users()->attach($this->both->id, ['type' => 'inspection']);
        $this->notAuthorized->users()->attach($this->onlyOther->id, ['type' => 'maintenance']);
        $this->otherClient->users()->attach($this->onlyOther->id, ['type' => 'inspection']);

        foreach ([$this->both, $this->onlyOther, $this->unassigned] as $t) {
            app(TechnicianPhotoService::class)->replace($t, $t, UploadedFile::fake()->image('f.jpg', 200, 200));
        }
        auth()->logout();

        $this->portal = User::factory()->create(['email' => 'consorcio@uno.test', 'name' => 'Administración Uno']);
        $this->portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $companyId, 'client_id' => $this->client->id])->save();
        $this->portal->portalBuildings()->sync([$this->mine->id]);
    }

    private function asPortal(?User $user = null): void
    {
        $this->flushSession();
        $this->actingAs(($user ?? $this->portal)->fresh());
    }

    public function test_the_client_sees_only_the_technicians_of_its_buildings(): void
    {
        $this->asPortal();
        $html = $this->get(route('portal.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-portal-responsibles', $html);
        $this->assertStringContainsString('Lucas Fernández', $html);
        $this->assertStringContainsString('Mantenimiento e Inspección', $html);
        $this->assertStringContainsString('Torre Autorizada', $html);
        // Nadie sin relación con SUS edificios autorizados.
        $this->assertStringNotContainsString('Pedro Ajeno', $html);
        $this->assertStringNotContainsString('Sofía Sin Edificios', $html);
        // Contacto: solo el de la empresa; nunca los datos personales del técnico.
        $this->assertStringContainsString('011 4000-1000', $html);
        $this->assertStringContainsString('contacto@norte.test', $html);
        foreach ([$this->both, $this->onlyOther, $this->unassigned] as $t) {
            $this->assertStringNotContainsString($t->email, $html);
            $this->assertStringNotContainsString($t->phone, $html);
        }
        // La foto, por la ruta del portal (no la del panel).
        $this->assertStringContainsString(PortalResponsibles::photoUrl($this->both->fresh()), $html);
        $this->assertStringNotContainsString('/files/technician-photos/', $html);

        // La ficha del edificio muestra a sus responsables; la de un edificio no autorizado, 404.
        $this->get(route('portal.building', $this->mine))->assertOk()->assertSee('Responsables de este edificio')->assertSee('Lucas Fernández')->assertDontSee('Pedro Ajeno');
        $this->get(route('portal.building', $this->notAuthorized))->assertNotFound();
        $this->get(route('portal.building', $this->otherClient))->assertNotFound();

        // Sin técnicos asignados: un aviso, sin listar a nadie.
        $this->mine->users()->detach();
        $this->get(route('portal.home'))->assertOk()->assertSee('todavía no asignó técnicos')->assertDontSee('Lucas Fernández');
    }

    public function test_photos_are_served_only_for_responsibles_of_the_client(): void
    {
        $this->asPortal();
        $mine = PortalResponsibles::photoUrl($this->both->fresh());
        $response = $this->get($mine)->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        // Técnicos de otros edificios o sin edificios: 404, aunque se conozca su id y versión.
        $this->get(PortalResponsibles::photoUrl($this->onlyOther->fresh()))->assertNotFound();
        $this->get(PortalResponsibles::photoUrl($this->unassigned->fresh()))->assertNotFound();
        // Versión vieja o falsa, id de alguien que no es técnico: 404.
        $this->get(route('portal.responsible-photo', ['user' => $this->both->id, 'v' => 'x']))->assertNotFound();
        $this->get(route('portal.responsible-photo', ['user' => $this->both->id]))->assertNotFound();
        $this->get(route('portal.responsible-photo', ['user' => $this->a['admin']->id, 'v' => $this->both->fresh()->photoVersion()]))->assertNotFound();
        // La ruta del panel sigue cerrada para el portal.
        $this->get($this->both->fresh()->photoUrl())->assertNotFound();

        // Al quitarle el edificio, o desactivarlo, deja de verse.
        $this->mine->users()->detach($this->both->id);
        $this->get($mine)->assertNotFound();
        $this->mine->users()->attach($this->both->id, ['type' => 'maintenance']);
        $this->get($mine)->assertOk();
        $this->both->delete();
        $this->get($mine)->assertNotFound();
        $this->get(route('portal.home'))->assertDontSee('Lucas Fernández');
    }

    public function test_access_follows_the_authorized_buildings_and_the_membership(): void
    {
        // Al autorizarle otro edificio, aparecen sus responsables (y su foto).
        $this->portal->portalBuildings()->attach($this->notAuthorized->id);
        $this->asPortal();
        $this->get(route('portal.home'))->assertSee('Pedro Ajeno')->assertSee('Torre Sin Permiso')->assertDontSee('Torre De Otro Cliente');
        $this->get(PortalResponsibles::photoUrl($this->onlyOther->fresh()))->assertOk();

        // Sacarle el edificio lo vuelve a ocultar.
        $this->portal->portalBuildings()->detach($this->notAuthorized->id);
        $this->asPortal();
        $this->get(route('portal.home'))->assertDontSee('Pedro Ajeno');
        $this->get(PortalResponsibles::photoUrl($this->onlyOther->fresh()))->assertNotFound();

        // Membresía desactivada: sin portal ni fotos.
        PortalMembership::where('user_id', $this->portal->id)->update(['deactivated_at' => now()]);
        $this->asPortal();
        $this->assertNotSame(200, $this->get(PortalResponsibles::photoUrl($this->both->fresh()))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('portal.home'))->getStatusCode());
        PortalMembership::where('user_id', $this->portal->id)->update(['deactivated_at' => null]);

        // Empresa sin acceso vigente: el portal no muestra nada (403).
        $this->a['company']->forceFill(['is_active' => false])->save();
        $this->asPortal();
        $this->assertNotSame(200, $this->get(PortalResponsibles::photoUrl($this->both->fresh()))->getStatusCode());
    }

    public function test_responsibles_never_cross_companies(): void
    {
        // Otra empresa con su cliente, edificio y técnico; la misma persona tiene acceso en las dos.
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $bClient = Client::factory()->create(['company_id' => $b['company']->id, 'name' => 'Consorcio B']);
        $bBuilding = Building::factory()->create(['company_id' => $b['company']->id, 'client_id' => $bClient->id, 'name' => 'Edificio de B']);
        $bTech = User::factory()->technician()->create(['company_id' => $b['company']->id, 'name' => 'Técnico de B']);
        $bBuilding->users()->attach($bTech->id, ['type' => 'maintenance']);
        app(TechnicianPhotoService::class)->replace($bTech, $bTech, UploadedFile::fake()->image('b.jpg', 200, 200));
        auth()->logout();
        // Un técnico de B asignado (por error de datos) a un edificio de A no se muestra en el portal de A.
        $this->mine->users()->attach($bTech->id, ['type' => 'inspection']);
        (new PortalMembership)->forceFill(['user_id' => $this->portal->id, 'client_id' => $bClient->id, 'company_id' => $b['company']->id, 'activated_at' => now()])->save();
        $this->portal->portalBuildings()->attach($bBuilding->id);

        // Empresa activa A: solo los de A.
        $this->asPortal();
        $html = $this->get(route('portal.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Lucas Fernández', $html);
        $this->assertStringNotContainsString('Técnico de B', $html);
        $this->assertStringNotContainsString('Edificio de B', $html);
        $this->get(PortalResponsibles::photoUrl($bTech->fresh()))->assertNotFound();

        // Cambia a B: solo los de B; la foto de A ya no responde.
        $this->post(route('portal.switch-company'), ['company' => $b['company']->id])->assertRedirect(route('portal.home'));
        $html = $this->get(route('portal.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Técnico de B', $html);
        $this->assertStringContainsString('Edificio de B', $html);
        $this->assertStringNotContainsString('Lucas Fernández', $html);
        $this->get(PortalResponsibles::photoUrl($bTech->fresh()))->assertOk();
        $this->get(PortalResponsibles::photoUrl($this->both->fresh()))->assertNotFound();

        // Un cliente de B que no tiene acceso en A no ve a nadie de A.
        auth()->logout();
        $bPortal = User::factory()->create(['email' => 'cliente@b.test']);
        $bPortal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $b['company']->id, 'client_id' => $bClient->id])->save();
        $bPortal->portalBuildings()->sync([$bBuilding->id, $this->mine->id]); // un edificio de A colado: no cuenta
        $this->asPortal($bPortal);
        $this->get(route('portal.home'))->assertOk()->assertDontSee('Lucas Fernández');
        $this->get(PortalResponsibles::photoUrl($this->both->fresh()))->assertNotFound();
    }

    public function test_each_technician_appears_once_with_the_roles_of_each_building(): void
    {
        // Lucas: mantenimiento e inspección en Torre Autorizada; solo inspección en un segundo edificio autorizado.
        $second = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Anexo Norte']);
        $second->users()->attach($this->both->id, ['type' => 'inspection']);
        // Un rol de un edificio de otro cliente no se le atribuye acá.
        $this->otherClient->users()->attach($this->both->id, ['type' => 'maintenance']);
        $this->portal->portalBuildings()->attach($second->id);
        $this->asPortal();

        $rows = PortalResponsibles::for($this->portal->fresh());
        $this->assertCount(1, $rows->filter(fn ($r) => $r['user']->is($this->both)));
        $lucas = $rows->first(fn ($r) => $r['user']->is($this->both));
        $this->assertSame(['Mantenimiento', 'Inspección'], $lucas['roles']);
        $this->assertSame([['name' => 'Anexo Norte', 'roles' => ['Inspección']], ['name' => 'Torre Autorizada', 'roles' => ['Mantenimiento', 'Inspección']]], $lucas['buildings']);
        $this->assertFalse($lucas['same_roles']);

        $html = $this->get(route('portal.home'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-responsible="'.$this->both->id.'"'));
        $this->assertStringContainsString('Anexo Norte (Inspección), Torre Autorizada (Mantenimiento e Inspección)', $html);
        $this->assertStringNotContainsString('Torre De Otro Cliente', $html);
        // En la ficha de cada edificio, solo el rol que tiene en ese edificio.
        $this->assertMatchesRegularExpression('#Lucas Fernández</strong>\s*<span class="p-muted"> · Inspección</span>#u', $this->get(route('portal.building', $second))->getContent());

        // Se retira la inspección de Torre Autorizada: queda el rol vigente.
        DB::table('building_user')->where('building_id', $this->mine->id)->where('user_id', $this->both->id)->where('type', 'inspection')->delete();
        $lucas = PortalResponsibles::for($this->portal->fresh())->first(fn ($r) => $r['user']->is($this->both));
        $this->assertSame([['name' => 'Anexo Norte', 'roles' => ['Inspección']], ['name' => 'Torre Autorizada', 'roles' => ['Mantenimiento']]], $lucas['buildings']);

        // Sin asignaciones en sus edificios: deja de aparecer y su foto no responde.
        $url = PortalResponsibles::photoUrl($this->both->fresh());
        $this->get($url)->assertOk();
        DB::table('building_user')->where('user_id', $this->both->id)->whereIn('building_id', [$this->mine->id, $second->id])->delete();
        $this->get(route('portal.home'))->assertDontSee('Lucas Fernández');
        $this->get($url)->assertNotFound();

        // Reasignado y luego desactivado: tampoco.
        $second->users()->attach($this->both->id, ['type' => 'maintenance']);
        $this->get($url)->assertOk();
        $this->both->delete();
        $this->get(route('portal.home'))->assertDontSee('Lucas Fernández');
        $this->get($url)->assertNotFound();
    }

    public function test_the_portal_is_read_only_and_the_panel_stays_closed(): void
    {
        $this->asPortal();
        $url = PortalResponsibles::photoUrl($this->both->fresh());
        $before = [$this->both->fresh()->avatar, $this->mine->users()->count()];

        // No hay nada que modificar: la foto solo admite GET, y las rutas de la empresa no le responden.
        $this->post($url)->assertStatus(405);
        $this->delete($url)->assertStatus(405);
        $this->assertNotSame(200, $this->get('/admin/users')->getStatusCode());
        $this->assertNotSame(200, $this->get('/admin/buildings/'.$this->mine->id.'/edit')->getStatusCode());
        $this->assertNotSame(200, $this->post('/'.$this->a['company']->slug.'/profile/foto', ['photo' => UploadedFile::fake()->image('x.jpg', 100, 100)])->getStatusCode());
        $this->assertFalse(app(TechnicianPhotoService::class)->canChange($this->portal, $this->both->fresh()));

        $this->assertSame($before, [$this->both->fresh()->avatar, $this->mine->users()->count()]);
        // Sin sesión: al login.
        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect();
    }
}
