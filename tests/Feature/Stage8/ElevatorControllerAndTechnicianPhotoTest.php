<?php

namespace Tests\Feature\Stage8;

use App\Filament\Resources\Elevators\Pages\EditElevator;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ActivityLog;
use App\Models\Elevator;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Services\Technicians\TechnicianPhotoService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 8.1 y 8.2: marca y modelo del control del ascensor (opcionales, sin
 * inventar valores) y foto de perfil del técnico (carga segura, privada,
 * reemplazo y eliminación, iniciales si no hay, permisos y empresas).
 */
class ElevatorControllerAndTechnicianPhotoTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->elevator = (new Elevator)->forceFill(['building_id' => $this->a['building']->id, 'company_id' => $this->a['company']->id, 'label' => 'Equipo C1', 'kind' => 'elevator', 'is_active' => true, 'manufacturer' => 'Otis']);
        $this->elevator->save();
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function photos(): TechnicianPhotoService
    {
        return app(TechnicianPhotoService::class);
    }

    private function denied(\Closure $call, int $status = 403): void
    {
        try {
            $call();
            $this->fail("Debía responder {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function invalid(\Closure $call): string
    {
        try {
            $call();
            $this->fail('Debía rechazar la foto.');
        } catch (ValidationException $e) {
            return (string) collect($e->errors()['photo'] ?? [])->first();
        }
    }

    /* ------------------------------------------------------------ 8.1 */

    public function test_controller_brand_and_model_are_optional_editable_and_shown(): void
    {
        // Los equipos existentes quedan vacíos: no se inventa nada.
        $this->assertNull($this->elevator->fresh()->controller_brand);
        $this->assertNull($this->elevator->fresh()->controller_model);

        $this->inPanel($this->a['admin']);
        Livewire::test(EditElevator::class, ['record' => $this->elevator->getRouteKey()])
            ->fillForm(['controller_brand' => 'Arkel', 'controller_model' => 'Arcode', 'controller' => 'Universal'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $this->elevator->fresh();
        $this->assertSame(['Arkel', 'Arcode', 'Universal'], [$fresh->controller_brand, $fresh->controller_model, $fresh->controller]);
        $this->assertEquals(['from' => null, 'to' => 'Arkel'], ActivityLog::where('action', 'elevator.updated')->latest('id')->first()->changes['controller_brand']); // MySQL reordena las claves del JSON

        $this->get('/admin/elevators/'.$this->elevator->id)->assertOk()->assertSee('Marca del control')->assertSee('Arkel')->assertSee('Arcode');

        // El cliente lo ve en la ficha de su edificio, en el portal.
        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);
        $this->flushSession();
        $this->actingAs($portal)->get(route('portal.building', $this->a['building']))->assertOk()->assertSee('Control: Arkel Arcode');
        $this->inPanel($this->a['admin']);

        // Vaciarlos vuelve a dejarlos sin dato.
        Livewire::test(EditElevator::class, ['record' => $this->elevator->getRouteKey()])
            ->fillForm(['controller_brand' => '', 'controller_model' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->elevator->fresh()->controller_brand);

        // No son asignables por fuera del formulario: no cambian la empresa ni el equipo.
        $this->elevator->fill(['controller_brand' => 'X', 'company_id' => 999, 'label' => 'Otro'])->save();
        $this->assertSame([$this->a['company']->id, 'Equipo C1', 'X'], [(int) $this->elevator->fresh()->company_id, $this->elevator->fresh()->label, $this->elevator->fresh()->controller_brand]);
    }

    /* ------------------------------------------------------------ 8.2 */

    public function test_a_technician_uploads_replaces_and_removes_their_own_photo(): void
    {
        $tech = $this->a['technician'];
        $slug = $this->a['company']->slug;
        $this->actingAs($tech);

        // Sin foto: iniciales.
        $this->get("/{$slug}/profile")->assertOk()->assertSee('data-profile-photo', false)->assertSee('data-user-initials', false);

        $this->post("/{$slug}/profile/foto", ['photo' => UploadedFile::fake()->image('yo.png', 1200, 800)])->assertRedirect()->assertSessionHasNoErrors();
        $first = $tech->fresh()->avatar;
        $this->assertTrue(TechnicianPhotoService::hasSafePath($first));
        $this->assertStringStartsWith('avatars/'.$this->a['company']->id.'/', $first);
        Storage::disk('local')->assertExists($first);
        // Re-codificada: JPEG cuadrado de 400 px (nunca el archivo original).
        [$w, $h, $type] = getimagesize(Storage::disk('local')->path($first));
        $this->assertSame([400, 400, IMAGETYPE_JPEG], [$w, $h, $type]);
        $this->get("/{$slug}/profile")->assertSee('data-user-photo', false);

        // Reemplazo: la anterior se borra.
        $this->post("/{$slug}/profile/foto", ['photo' => UploadedFile::fake()->image('nueva.jpg', 500, 500)])->assertSessionHasNoErrors();
        $second = $tech->fresh()->avatar;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        // La ve (archivo privado, sin caché pública).
        $response = $this->get($tech->fresh()->photoUrl())->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        // Eliminación: vuelve a las iniciales y el archivo no queda.
        $this->delete("/{$slug}/profile/foto")->assertRedirect();
        $this->assertNull($tech->fresh()->avatar);
        Storage::disk('local')->assertMissing($second);
        $this->get(route('technician-photos.show', ['user' => $tech->id]))->assertNotFound();
        $this->get($tech->photoUrl() ?? '/files/technician-photos/'.$tech->id)->assertNotFound();
    }

    public function test_only_valid_images_within_the_limits_are_accepted(): void
    {
        $tech = $this->a['technician'];
        $this->actingAs($tech);

        $this->assertStringContainsString('JPG, PNG o WebP', $this->invalid(fn () => $this->photos()->replace($tech, $tech, UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf'))));
        $this->assertStringContainsString('2 MB', $this->invalid(fn () => $this->photos()->replace($tech, $tech, UploadedFile::fake()->image('enorme.jpg', 100, 100)->size(3000))));
        // Un script con extensión de imagen: el contenido no es una imagen.
        $fake = UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "hola"; ?>');
        $this->invalid(fn () => $this->photos()->replace($tech, $tech, $fake));
        $gif = UploadedFile::fake()->image('anim.gif', 50, 50);
        $this->invalid(fn () => $this->photos()->replace($tech, $tech, $gif));

        $this->assertNull($tech->fresh()->avatar);
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));

        // Por la ruta: la misma validación, sin archivos sueltos.
        $this->post('/'.$this->a['company']->slug.'/profile/foto', ['photo' => $fake])->assertSessionHasErrors('photo');
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));
    }

    public function test_the_owner_manages_photos_from_the_panel_and_staff_only_see_them(): void
    {
        $tech = $this->a['technician'];

        $this->inPanel($this->a['admin']);
        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])
            ->assertActionVisible('photo')->assertActionHidden('removePhoto')
            ->callAction('photo', ['photo' => UploadedFile::fake()->image('t.jpg', 600, 600)])
            ->assertHasNoActionErrors();
        $this->assertNotNull($path = $tech->fresh()->avatar);
        $this->assertSame(1, ActivityLog::where('action', 'user.photo_updated')->where('subject_id', $tech->id)->count());
        $this->get($tech->fresh()->photoUrl())->assertOk();
        $this->get('/admin/users')->assertOk()->assertSee('data-technician-photo', false)->assertSee(route('technician-photos.show', ['user' => $tech->id]), false);

        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->callAction('removePhoto');
        $this->assertNull($tech->fresh()->avatar);
        Storage::disk('local')->assertMissing($path);
        $this->photos()->replace($this->a['admin'], $tech->fresh(), UploadedFile::fake()->image('t.jpg', 300, 300));

        // Un administrativo con «Técnicos» la ve, pero no la cambia.
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ana Oficina', 'email' => 'ana@demo.test', 'password' => 'clave-segura-123'], ['technicians' => 'view']);
        $this->inPanel($staff);
        $this->get($tech->fresh()->photoUrl())->assertOk();
        $this->assertFalse($this->photos()->canChange($staff, $tech->fresh()));
        $this->denied(fn () => $this->photos()->remove($staff, $tech->fresh()));
        $this->denied(fn () => $this->photos()->replace($staff, $tech->fresh(), UploadedFile::fake()->image('x.jpg', 100, 100)));

        // Otro técnico de la misma empresa no la cambia ni la descarga.
        $other = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => 'technician', 'job_type' => 'maintenance']);
        $this->flushSession();
        $this->actingAs($other);
        $this->denied(fn () => $this->photos()->remove($other, $tech->fresh()));
        $this->get($tech->fresh()->photoUrl())->assertNotFound();

        // Solo técnicos tienen foto: el dueño no se carga una a sí mismo ni a un administrativo.
        $this->inPanel($this->a['admin']);
        $this->assertFalse($this->photos()->canChange($this->a['admin'], $this->a['admin']));
        $this->assertFalse($this->photos()->canChange($this->a['admin'], $staff));
    }

    public function test_photos_never_cross_companies_and_need_a_session(): void
    {
        $tech = $this->a['technician'];
        $this->inPanel($this->a['admin']);
        $this->photos()->replace($this->a['admin'], $tech, UploadedFile::fake()->image('t.jpg', 300, 300));
        $url = $tech->fresh()->photoUrl();

        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');

        // Otra empresa: ni la ve ni la cambia.
        $this->inPanel($b['admin']);
        $this->get($url)->assertNotFound();
        $this->denied(fn () => $this->photos()->remove($b['admin'], $tech->fresh()));
        $this->flushSession();
        $this->actingAs($b['technician']);
        $this->get($url)->assertNotFound();

        // Sin sesión: al login, nunca el archivo.
        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect();

        // SuperAdmin: dentro de la empresa elegida, sí; sin elegirla, no.
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($super);
        $this->get($url)->assertNotFound();
        session(['selected_company_id' => $this->a['company']->id]);
        $this->get($url)->assertOk();
        $this->assertTrue($this->photos()->canChange($super, $tech->fresh()));

        // La ruta no sirve otras rutas guardadas en la columna, aunque la versión coincida.
        $tech->forceFill(['avatar' => 'elevators/'.$this->a['company']->id.'/doc.pdf'])->saveQuietly();
        Storage::disk('local')->put('elevators/'.$this->a['company']->id.'/doc.pdf', 'x');
        $this->inPanel($this->a['admin']);
        $this->get($tech->fresh()->photoUrl())->assertNotFound();
    }

    public function test_manipulated_ids_versions_and_paths_never_reveal_a_photo(): void
    {
        $tech = $this->a['technician'];
        $this->inPanel($this->a['admin']);
        $this->photos()->replace($this->a['admin'], $tech, UploadedFile::fake()->image('t.jpg', 300, 300));
        $tech = $tech->fresh();
        $base = '/files/technician-photos/';

        $this->get($tech->photoUrl())->assertOk();
        // Sin versión, con otra o con una inyectada: 404.
        $this->get($base.$tech->id)->assertNotFound();
        $this->get($base.$tech->id.'?v=0000000000000000')->assertNotFound();
        $this->get($base.$tech->id.'?v[]='.$tech->photoVersion())->assertNotFound();
        // Ids que no son números, o de usuarios que no son técnicos: 404.
        $this->get($base.'..%2F..%2F.env?v='.$tech->photoVersion())->assertNotFound();
        $this->get($base.$this->a['admin']->id.'?v='.$tech->photoVersion())->assertNotFound();
        $this->get($base.'999999?v='.$tech->photoVersion())->assertNotFound();

        // Un admin con la columna cargada a mano tampoco se sirve (solo técnicos).
        $this->a['admin']->forceFill(['avatar' => $tech->avatar])->saveQuietly();
        $this->get($this->a['admin']->fresh()->photoUrl())->assertNotFound();

        // La foto de un técnico apuntando a la carpeta de OTRA empresa: 404.
        $b = $this->makeTenant();
        $foreign = 'avatars/'.$b['company']->id.'/'.str_repeat('a', 40).'.jpg';
        Storage::disk('local')->put($foreign, 'x');
        $other = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => 'technician', 'job_type' => 'maintenance']);
        $other->forceFill(['avatar' => $foreign])->saveQuietly();
        $this->inPanel($this->a['admin']);
        $this->get($other->fresh()->photoUrl())->assertNotFound();

        // Técnico desactivado: su foto deja de servirse.
        $tech->delete();
        $this->get($tech->photoUrl())->assertNotFound();
    }

    public function test_replaced_or_removed_photos_are_gone_from_urls_cache_and_disk(): void
    {
        $tech = $this->a['technician'];
        $this->inPanel($this->a['admin']);
        $this->photos()->replace($this->a['admin'], $tech, UploadedFile::fake()->image('1.jpg', 300, 300));
        $firstUrl = $tech->fresh()->photoUrl();
        $firstPath = $tech->fresh()->avatar;

        // El navegador vuelve a preguntar siempre (no-cache) y, sin cambios, recibe 304.
        $response = $this->get($firstUrl)->assertOk();
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));
        $etag = $response->headers->get('ETag');
        $this->get($firstUrl, ['If-None-Match' => $etag])->assertStatus(304);

        // Reemplazada: la URL y el ETag anteriores ya no responden; solo queda el archivo nuevo.
        $this->photos()->replace($this->a['admin'], $tech->fresh(), UploadedFile::fake()->image('2.jpg', 300, 300));
        $this->get($firstUrl)->assertNotFound();
        $this->get($firstUrl, ['If-None-Match' => $etag])->assertNotFound();
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertSame([$tech->fresh()->avatar], Storage::disk('local')->allFiles('avatars'));
        $secondUrl = $tech->fresh()->photoUrl();
        $this->assertNotSame($firstUrl, $secondUrl);
        $this->get($secondUrl)->assertOk();

        // Quitada: ninguna URL anterior responde y no queda ningún archivo.
        $this->photos()->remove($this->a['admin'], $tech->fresh());
        $this->get($secondUrl)->assertNotFound();
        $this->get($firstUrl)->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));

        // Varios reemplazos seguidos (técnico y dueño): siempre un solo archivo, el vigente.
        foreach ([$tech, $this->a['admin'], $tech, $this->a['admin']] as $actor) {
            $actor->is($tech) ? $this->actingAs($tech) : $this->inPanel($actor);
            $this->photos()->replace($actor, $tech->fresh(), UploadedFile::fake()->image('n.jpg', 200, 200));
        }
        $this->assertSame([$tech->fresh()->avatar], Storage::disk('local')->allFiles('avatars'));
        // Quitar sin foto no hace nada ni registra.
        $this->photos()->remove($this->a['admin'], $tech->fresh());
        $before = ActivityLog::where('action', 'user.photo_removed')->count();
        $this->photos()->remove($this->a['admin'], $tech->fresh());
        $this->assertSame($before, ActivityLog::where('action', 'user.photo_removed')->count());
    }

    public function test_who_can_see_a_photo_by_role(): void
    {
        $tech = $this->a['technician'];
        $this->inPanel($this->a['admin']);
        $this->photos()->replace($this->a['admin'], $tech, UploadedFile::fake()->image('t.jpg', 300, 300));
        $url = $tech->fresh()->photoUrl();
        $this->get($url)->assertOk(); // dueño

        // Administrativo: con «Técnicos», sí; sin esa área o desactivado, no.
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $this->actingAs($this->a['admin']);
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ana Oficina', 'email' => 'ana@demo.test', 'password' => 'clave-segura-123'], ['visits' => 'manage']);
        $this->inPanel($staff);
        $this->get($url)->assertNotFound();
        $this->inPanel($this->a['admin']);
        app(StaffManager::class)->setPermissions($this->a['admin'], $staff, ['technicians' => 'view']);
        $this->inPanel($staff->fresh());
        $this->get($url)->assertOk();
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->deactivate($this->a['admin'], $staff->fresh());
        $this->flushSession();
        $this->actingAs(User::withTrashed()->find($staff->id));
        $this->assertNotSame(200, $this->get($url)->getStatusCode());

        // Usuario del portal del cliente de ese edificio: todavía no (llega con el PR 2), 404.
        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);
        $this->flushSession();
        $this->actingAs($portal);
        $this->assertNotSame(200, $this->get($url)->getStatusCode());

        // SuperAdmin con OTRA empresa elegida: 404; con la del técnico: sí.
        auth()->logout();
        $b = $this->makeTenant();
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($super);
        session(['selected_company_id' => $b['company']->id]);
        $this->get($url)->assertNotFound();
        $this->assertFalse($this->photos()->canChange($super, $tech->fresh()));
        session(['selected_company_id' => $this->a['company']->id]);
        $this->get($url)->assertOk();

        // El propio técnico, sí; con la empresa sin acceso vigente, ya no.
        $this->flushSession();
        $this->actingAs($tech->fresh());
        $this->get($url)->assertOk();
        $this->a['company']->forceFill(['is_active' => false])->save();
        $this->actingAs($tech->fresh()); // instancia nueva: como en un pedido real, sin la empresa en memoria
        $this->assertSame(403, $this->get($url)->getStatusCode());
    }
}
