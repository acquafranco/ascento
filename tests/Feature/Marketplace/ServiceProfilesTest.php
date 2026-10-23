<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\MyServiceProfile;
use App\Filament\Pages\Marketplace\ServiceRequests;
use App\Filament\Resources\Marketplace\Pages\ListServiceCategories;
use App\Filament\Resources\Marketplace\Pages\ListServiceContactRequests;
use App\Filament\Resources\Marketplace\Pages\ListServiceProfiles;
use App\Filament\Resources\Marketplace\ServiceContactRequestResource;
use App\Models\Company;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\ServiceCategory;
use App\Models\Marketplace\ServiceContactMessage;
use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileReport;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierProduct;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Companies\CompanyEraser;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Marketplace\PublicationLimits;
use App\Services\Marketplace\ServiceContacts;
use App\Services\Marketplace\ServiceProfiles;
use App\Services\Marketplace\SupplierEraser;
use App\Services\Notifications\Notifier;
use App\Support\Marketplace\ServiceDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * «Profesionales y servicios»: perfiles gratuitos de proveedores y de
 * empresas de mantenimiento (misma cuenta), catálogo público sin datos
 * privados, solicitudes de contacto aisladas entre empresas y profesionales,
 * moderación con auditoría y conservación del historial.
 */
class ServiceProfilesTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, PassesRegistrationChallenge, RefreshDatabase;

    private function categoryId(string $slug = 'diagnostico-y-reparacion-de-placas'): int
    {
        return (int) ServiceCategory::where('slug', $slug)->value('id');
    }

    private function profileData(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'independent', 'public_name' => 'Placas Pereyra', 'headline' => 'Reparación de placas en 48 h',
            'description' => 'Diagnóstico y reparación de placas de maniobra y variadores de frecuencia para ascensores.',
            'modality' => 'both', 'categories' => [$this->categoryId()], 'services' => 'Reparación de placas, Diagnóstico remoto',
            'brands' => 'Schindler, Otis', 'country' => 'AR', 'covers_whole_country' => false, 'provinces' => ['Córdoba'], 'localities' => 'Río Cuarto',
        ], $overrides);
    }

    /** Perfil de un usuario dueño, publicado y (por defecto) aprobado por el SuperAdmin. */
    private function profileFor(User $owner, array $overrides = [], bool $approve = true): ServiceProfile
    {
        $profiles = app(ServiceProfiles::class);
        $profile = $profiles->save($owner, $this->profileData($overrides));
        $profiles->publish($owner, $profile);
        if ($approve) {
            $profiles->approve(User::where('is_super_admin', true)->first() ?? User::factory()->superAdmin()->create(), $profile->fresh());
        }

        return $profile->fresh();
    }

    private function contact(array $overrides = []): array
    {
        return array_merge(['contact_name' => 'Ana Compras', 'contact_email' => 'ana@empresa.test', 'contact_phone' => '11 4444-5555',
            'subject' => 'Placa que no arranca', 'need' => 'Placa de maniobra Schindler que no arranca después de un corte de luz.', 'modality' => 'onsite', 'location' => 'Córdoba'], $overrides);
    }

    public function test_a_services_only_account_registers_without_tax_id_and_can_enable_products_later(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->post(route('suppliers.register'), [...$this->registrationProof(),
            'offer' => 'services', 'supplier_name' => 'Pereyra Electrónica', 'country' => 'AR', 'phone' => '351 555-0000', 'coverage' => 'Córdoba',
            'name' => 'Luis Pereyra', 'email' => 'luis@pereyra.test', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'terms' => '1',
        ])->assertRedirect(route('suppliers.service-profile.edit'));

        $supplier = Supplier::sole();
        $this->assertNull($supplier->tax_id);
        $this->assertFalse($supplier->sellsProducts());
        $this->assertSame(Supplier::PENDING, $supplier->status); // la aprobación del SuperAdmin se mantiene
        $this->assertSame(1, $super->notifications()->where('data->title', 'Proveedor nuevo para revisar')->count());

        User::where('supplier_id', $supplier->id)->update(['email_verified_at' => now()]);
        $this->actingAs(User::where('supplier_id', $supplier->id)->sole());
        // Sin catálogo de productos hasta cargar la identificación fiscal.
        $this->get(route('suppliers.products.index'))->assertRedirect(route('suppliers.profile.edit'));
        $this->get(route('suppliers.home'))->assertOk()->assertDontSee('Mi catálogo')->assertSee('Perfil profesional');
        $this->post(route('suppliers.profile.enable-products'), ['tax_id' => ''])->assertSessionHasErrors('tax_id');
        $this->post(route('suppliers.profile.enable-products'), ['tax_id' => '30-71234567-1'])->assertRedirect(route('suppliers.products.index'));
        $this->assertTrue($supplier->fresh()->sellsProducts());
        $this->assertNotNull($supplier->fresh()->tax_id);

        // Vender productos sigue exigiendo la identificación fiscal en el registro.
        auth()->logout();
        $this->post(route('suppliers.register'), [...$this->registrationProof(),
            'offer' => 'both', 'supplier_name' => 'Otro', 'country' => 'AR', 'phone' => '351 555-0001', 'coverage' => 'Córdoba', 'categories' => ['doors'],
            'name' => 'Otro', 'email' => 'otro@otro.test', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'terms' => '1',
        ])->assertSessionHasErrors('tax_id');
        $this->assertSame(1, Supplier::count());
    }

    public function test_a_profile_is_free_needs_review_and_does_not_use_product_publications(): void
    {
        $super = User::factory()->superAdmin()->create();
        [$supplier, $user] = $this->makeSupplier();
        foreach (range(1, 20) as $i) {
            $this->makeProduct($supplier, ['name' => 'Producto '.$i]);
        }
        $usage = PublicationLimits::usage($supplier);

        $this->actingAs($user)->put(route('suppliers.service-profile.update'), $this->profileData(['categories' => []]))->assertSessionHasErrors('categories');
        $this->put(route('suppliers.service-profile.update'), $this->profileData())->assertRedirect()->assertSessionHasNoErrors();
        $profile = ServiceProfile::sole();
        $this->assertSame((int) $supplier->id, (int) $profile->supplier_id);
        $this->assertSame(ServiceProfile::DRAFT, $profile->status);

        // Publicar: espera la revisión de Ascento y recién ahí se ve.
        $this->post(route('suppliers.service-profile.publish'))->assertSessionHas('status');
        $this->assertSame(1, $super->notifications()->where('data->title', 'Perfil profesional para revisar')->count());
        $this->assertFalse($profile->fresh()->isVisible());
        auth()->logout();
        $this->get(route('services.show', $profile->slug))->assertNotFound();
        app(ServiceProfiles::class)->approve($super, $profile->fresh());
        $this->assertTrue($profile->fresh()->isVisible());
        $this->assertSame(1, $user->notifications()->where('data->title', 'Aprobamos tu perfil profesional')->count());

        // Mismo usuario y misma cuenta: productos y servicios a la vez, sin tocar el límite de publicaciones.
        $this->assertEquals($usage, PublicationLimits::usage($supplier->fresh()));
        $this->assertSame(20, SupplierProduct::visible()->where('supplier_id', $supplier->id)->count());

        // Gratis y sin vencimiento: sin suscripción, sigue visible años después.
        $this->travel(3)->years();
        $this->assertTrue($profile->fresh()->isVisible());
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_a_maintenance_company_publishes_its_own_profile_with_the_same_account(): void
    {
        $super = User::factory()->superAdmin()->create();
        $a = $this->makeTenant();
        $staff = User::factory()->create(['company_id' => $a['company']->id]);
        $staff->forceFill(['role' => User::ROLE_STAFF])->save();

        $this->actingInPanel($a['admin']);
        Livewire::test(MyServiceProfile::class)
            ->set('data.description', 'Empresa de mantenimiento con taller propio de reparación de placas y variadores.')
            ->set('data.categories', [(string) $this->categoryId()])->set('data.provinces', ['Córdoba'])
            ->call('save')->assertHasNoErrors()->assertNotified('Perfil profesional guardado')
            ->call('publish')->assertNotified('Perfil enviado a revisión');
        $profile = ServiceProfile::sole();
        $this->assertSame((int) $a['company']->id, (int) $profile->company_id);
        $this->assertNull($profile->supplier_id);
        $this->assertSame($a['company']->name, $profile->public_name);
        app(ServiceProfiles::class)->approve($super, $profile);
        $this->assertTrue($profile->fresh()->isVisible());

        // Solo el dueño de la empresa lo administra; un técnico tampoco.
        $this->actingInPanel($a['technician']);
        $this->assertFalse(MyServiceProfile::canAccess());
        $this->actingInPanel($staff->fresh());
        $this->assertFalse(MyServiceProfile::canAccess());
        $this->get(MyServiceProfile::getUrl())->assertStatus(403);

        // Sin acceso vigente (suscripción vencida o empresa desactivada): no se ofrece ni recibe solicitudes.
        $a['company']->forceFill(['is_active' => false])->save();
        $this->assertFalse($profile->fresh()->isVisible());
        $this->get(route('services.index'))->assertDontSee($profile->public_name);
    }

    public function test_the_public_directory_searches_filters_paginates_and_never_shows_private_data(): void
    {
        User::factory()->superAdmin()->create();
        [$supplier, $user] = $this->makeSupplier(Supplier::APPROVED, 'Electrónica Pereyra');
        $placas = $this->profileFor($user);
        [, $remoteUser] = $this->makeSupplier(Supplier::APPROVED, 'Remotos SA');
        $remote = $this->profileFor($remoteUser, ['public_name' => 'Asistencia Remota Sur', 'modality' => 'remote', 'brands' => 'Orona', 'provinces' => [], 'covers_whole_country' => true,
            'categories' => [$this->categoryId('asistencia-tecnica-remota')], 'description' => 'Asistencia técnica remota para configurar variadores y controles electrónicos.']);
        // No visibles: sin aprobar, borrador, archivado, oculto, suspendido y de un proveedor suspendido.
        [, $u1] = $this->makeSupplier(Supplier::APPROVED, 'Pendiente');
        $pending = $this->profileFor($u1, ['public_name' => 'Perfil Pendiente'], approve: false);
        [, $u2] = $this->makeSupplier(Supplier::APPROVED, 'Borrador');
        app(ServiceProfiles::class)->save($u2, $this->profileData(['public_name' => 'Perfil Borrador']));
        [, $u3] = $this->makeSupplier(Supplier::APPROVED, 'Archivado');
        $archived = $this->profileFor($u3, ['public_name' => 'Perfil Archivado']);
        app(ServiceProfiles::class)->archive($u3, $archived);
        [, $u4] = $this->makeSupplier(Supplier::APPROVED, 'Oculto');
        $hidden = $this->profileFor($u4, ['public_name' => 'Perfil Oculto']);
        $hidden->forceFill(['moderation' => ServiceProfile::HIDDEN])->save();
        [$s5, $u5] = $this->makeSupplier(Supplier::APPROVED, 'Suspendido');
        $this->profileFor($u5, ['public_name' => 'Perfil De Suspendido']);
        $s5->forceFill(['status' => Supplier::SUSPENDED])->save();

        $page = $this->get(route('services.index'))->assertOk()->assertSee('Placas Pereyra')->assertSee('Asistencia Remota Sur')
            ->assertDontSee('Perfil Pendiente')->assertDontSee('Perfil Borrador')->assertDontSee('Perfil Archivado')->assertDontSee('Perfil Oculto')->assertDontSee('Perfil De Suspendido');
        $this->get(route('services.show', $pending->slug))->assertNotFound();
        $this->get(route('services.show', $hidden->slug))->assertNotFound();

        // Perfil público: sin correos ni teléfonos de la cuenta, con la aclaración de información declarada.
        $this->get(route('services.show', $placas->slug))->assertOk()->assertSee('Reparación de placas')->assertSee('Schindler')
            ->assertSee('Ascento no verificó títulos, matrículas')->assertDontSee($supplier->contact_email)->assertDontSee($supplier->contact_phone)
            ->assertDontSee($user->email)->assertDontSee((string) $supplier->tax_id)->assertDontSee('verificado', false);
        $page->assertDontSee($supplier->contact_email);

        // Búsqueda y filtros (servidor, sin servicios externos).
        $names = fn (array $f) => ServiceDirectory::search($f)->getCollection()->pluck('public_name')->all();
        $this->assertSame(['Placas Pereyra'], $names(['q' => 'placas maniobra']));
        $this->assertSame(['Asistencia Remota Sur'], $names(['servicio' => 'asistencia-tecnica-remota']));
        $this->assertSame(['Placas Pereyra'], $names(['marca' => 'schíndler']));
        $this->assertEqualsCanonicalizing(['Asistencia Remota Sur', 'Placas Pereyra'], $names(['modalidad' => 'remote'])); // «presencial y remota» también
        $this->assertSame(['Placas Pereyra'], $names(['modalidad' => 'onsite']));
        $this->assertEqualsCanonicalizing(['Placas Pereyra', 'Asistencia Remota Sur'], $names(['pais' => 'AR', 'provincia' => 'Córdoba'])); // todo el país incluye Córdoba
        $this->assertSame(['Asistencia Remota Sur'], $names(['pais' => 'AR', 'provincia' => 'Mendoza']));
        $this->assertSame([], $names(['pais' => 'CL']));
        $this->assertSame([], $names(['q' => '100%_']));

        // Paginación.
        foreach (range(1, ServiceDirectory::PER_PAGE) as $i) {
            [, $u] = $this->makeSupplier(Supplier::APPROVED, 'Prof '.$i);
            $this->profileFor($u, ['public_name' => 'Técnico número '.$i]);
        }
        $this->assertSame(ServiceDirectory::PER_PAGE + 2, ServiceDirectory::search([])->total());
        $this->get(route('services.index', ['page' => 2]))->assertOk()->assertSee('Página 2 de 2');
    }

    public function test_a_company_sends_one_request_the_professional_answers_and_both_are_notified(): void
    {
        User::factory()->superAdmin()->create();
        [$supplier, $pro] = $this->makeSupplier();
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();

        // Sin cuenta: ve el perfil, pero contactar pide ingresar con una empresa.
        $this->get(route('services.show', $profile->slug))->assertSee('Ingresar con mi empresa para contactar');
        $this->actingAs($a['admin'])->get(route('services.show', $profile->slug))->assertSee('/admin/profesionales?perfil='.$profile->slug, false);

        $this->actingInPanel($a['admin']);
        $page = Livewire::test(ServiceRequests::class, ['perfil' => $profile->slug])->assertSee('Contale qué necesitás')
            ->set('form.subject', 'Placa que no arranca')->set('form.need', 'Placa de maniobra Schindler que no arranca después de un corte de luz.')
            ->call('send')->assertHasNoErrors()->assertNotified();
        $request = ServiceContactRequest::sole();
        $this->assertSame([(int) $a['company']->id, (int) $profile->id, ServiceContactRequest::OPEN], [(int) $request->company_id, (int) $request->service_profile_id, $request->status]);
        $this->assertSame(1, $pro->notifications()->where('data->title', 'Solicitud de contacto nueva')->count());

        // Mismo formulario dos veces = una sola solicitud; otra solicitud abierta al mismo perfil, no.
        $service = app(ServiceContacts::class);
        try {
            $service->create($a['admin'], $profile, $this->contact(), 'otra-clave');
            $this->fail('Abrió dos solicitudes al mismo perfil');
        } catch (ValidationException $e) {
            $this->assertSame(ServiceContacts::ALREADY_OPEN, collect($e->errors())->flatten()->first());
        }
        $this->assertSame(1, ServiceContactRequest::count());
        Livewire::test(ServiceRequests::class, ['perfil' => $profile->slug])->assertSee('Ya tenés una solicitud abierta');

        // El profesional la ve en su área, responde y la empresa recibe el aviso.
        $this->actingAs($pro)->get(route('suppliers.service-requests.index'))->assertOk()->assertSee($request->reference);
        $this->get(route('suppliers.service-requests.show', $request->reference))->assertOk()->assertSee($a['admin']->email)
            ->assertSee($a['company']->name);
        $this->post(route('suppliers.service-requests.reply', $request->reference), ['mensaje' => 'Hola, puedo verla el jueves.'])->assertSessionHasNoErrors();
        $this->assertSame(ServiceContactRequest::ANSWERED, $request->fresh()->status);
        $this->assertSame(1, $a['admin']->notifications()->where('data->title', 'Te respondió '.$profile->public_name)->count());

        $this->actingInPanel($a['admin']);
        Livewire::test(ServiceRequests::class, ['solicitud' => $request->id])->assertSee('Hola, puedo verla el jueves.')
            ->set('replyText', 'Perfecto, gracias.')->call('reply')->assertHasNoErrors()->call('close');
        $this->assertSame(ServiceContactRequest::CLOSED, $request->fresh()->status);
        $this->assertSame('company', $request->fresh()->closed_by);
        $this->assertSame(1, $pro->notifications()->where('data->title', 'Mensaje nuevo de '.$a['company']->name)->count());
        $this->assertSame(2, ServiceContactMessage::count());

        // Productos aparte: ningún pedido de repuestos ni cobro se crea.
        $this->assertSame(0, MarketplaceRequest::count());
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_companies_and_professionals_only_see_their_own_requests(): void
    {
        User::factory()->superAdmin()->create();
        [, $pro] = $this->makeSupplier();
        [, $otherPro] = $this->makeSupplier(Supplier::APPROVED, 'Otro profesional');
        $profile = $this->profileFor($pro);
        $this->profileFor($otherPro, ['public_name' => 'Otro']);
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $request = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'k');

        // Otra empresa: ni la lista ni el id manipulado.
        $this->actingInPanel($b['admin']);
        Livewire::test(ServiceRequests::class)->assertDontSee($request->reference);
        $this->get(ServiceRequests::getUrl(['solicitud' => $request->id]))->assertNotFound();
        // Otro profesional: 404 con la referencia ajena.
        $this->actingAs($otherPro)->get(route('suppliers.service-requests.show', $request->reference))->assertNotFound();
        $this->post(route('suppliers.service-requests.reply', $request->reference), ['mensaje' => 'Intruso'])->assertNotFound();
        // Un técnico de la misma empresa no tiene acceso; un proveedor no entra al panel.
        $this->actingInPanel($a['technician']);
        $this->assertFalse(ServiceRequests::canAccess());
        try {
            app(ServiceContacts::class)->reply($b['admin'], $request, 'Intruso');
            $this->fail('Respondió una solicitud ajena');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(0, ServiceContactMessage::count());

        // Una empresa no se escribe a su propio perfil.
        $this->actingInPanel($a['admin']);
        Livewire::test(MyServiceProfile::class)->set('data.description', 'Taller de placas propio para otras empresas de la zona y del país.')
            ->set('data.categories', [(string) $this->categoryId()])->set('data.provinces', ['Córdoba'])->call('save')->call('publish');
        $own = ServiceProfile::where('company_id', $a['company']->id)->sole();
        app(ServiceProfiles::class)->approve(User::where('is_super_admin', true)->first(), $own);
        $this->expectException(ValidationException::class);
        app(ServiceContacts::class)->create($a['admin'], $own->fresh(), $this->contact(), 'propio');
    }

    public function test_suspending_a_profile_blocks_new_requests_but_keeps_the_history(): void
    {
        $super = User::factory()->superAdmin()->create();
        [, $pro] = $this->makeSupplier();
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $request = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'k');

        $this->actingInPanel($super);
        Livewire::test(ListServiceProfiles::class)->callTableAction('suspend', $profile, ['reason' => 'Datos engañosos'])->assertNotified('Perfil suspendido');
        $profile->refresh();
        $this->assertSame(ServiceProfile::SUSPENDED, $profile->moderation);
        $this->assertFalse($profile->isVisible());
        $this->assertSame(1, $pro->notifications()->where('data->title', 'Suspendimos tu perfil profesional')->count());
        $this->assertSame(1, PlatformAuditLog::where('action', 'marketplace.service_profile.suspended')->count());

        try {
            app(ServiceContacts::class)->create($b['admin'], $profile, $this->contact(), 'k2');
            $this->fail('Recibió una solicitud suspendido');
        } catch (ValidationException) {
        }
        // Lo existente se conserva: la empresa lo ve; el profesional no puede responder ni editar.
        $this->assertNotNull($request->fresh());
        $this->actingInPanel($a['admin']);
        Livewire::test(ServiceRequests::class, ['solicitud' => $request->id])->assertSee($request->subject);
        $this->actingAs($pro)->post(route('suppliers.service-requests.reply', $request->reference), ['mensaje' => 'Hola'])->assertSessionHasErrors('mensaje');
        $this->put(route('suppliers.service-profile.update'), $this->profileData())->assertSessionHasErrors('perfil');

        // Reactivar: vuelve a verse.
        $this->actingInPanel($super);
        Livewire::test(ListServiceProfiles::class)->callTableAction('restore', $profile)->assertNotified('Perfil reactivado');
        $this->assertTrue($profile->fresh()->isVisible());
        $this->assertSame(1, ServiceContactRequest::count());
    }

    public function test_spam_limits_and_failed_notifications_never_duplicate_requests(): void
    {
        User::factory()->superAdmin()->create();
        [, $pro] = $this->makeSupplier();
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();

        // Un aviso que falla no rompe ni duplica: el reenvío devuelve la misma solicitud.
        $this->mock(Notifier::class, fn ($m) => $m->shouldReceive('sendToAccount')->andThrow(new \RuntimeException('correo caído')));
        $first = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'misma-clave');
        $again = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'misma-clave');
        $this->assertTrue($first->is($again));
        $this->assertSame(1, ServiceContactRequest::count());

        // Límite por usuario.
        $first->forceFill(['status' => ServiceContactRequest::CLOSED])->save();
        foreach (range(1, ServiceContacts::PER_USER_HOUR) as $i) {
            RateLimiter::hit('svc-request:user:'.$a['admin']->id, 3600);
        }
        try {
            app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'otra');
            $this->fail('No limitó');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('muchas solicitudes', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(1, ServiceContactRequest::count());
    }

    public function test_the_super_admin_opens_request_content_only_with_an_audited_reason(): void
    {
        $super = User::factory()->superAdmin()->create();
        [, $pro] = $this->makeSupplier();
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();
        $request = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'k');

        $this->actingInPanel($super);
        Livewire::test(ListServiceContactRequests::class)->assertSee($request->reference)->assertDontSee('ana@empresa.test')->assertDontSee('corte de luz');
        $this->get(ServiceContactRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('Abrir contenido')->assertDontSee('corte de luz');
        Livewire::test(ListServiceContactRequests::class)->callTableAction('open', $request, ['reason' => 'corto'])->assertHasTableActionErrors(['reason']);
        $this->assertSame(0, PlatformAuditLog::where('action', 'marketplace.service_request.viewed')->count());
        Livewire::test(ListServiceContactRequests::class)->callTableAction('open', $request, ['reason' => 'Reporte de fraude de la empresa compradora']);
        $log = PlatformAuditLog::where('action', 'marketplace.service_request.viewed')->sole();
        $this->assertStringContainsString('Reporte de fraude', json_encode($log->changes));
        $this->get(ServiceContactRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('corte de luz');
        $this->travel(ServiceContactRequestResource::UNLOCK_MINUTES + 1)->minutes();
        $this->get(ServiceContactRequestResource::getUrl('view', ['record' => $request]))->assertDontSee('corte de luz');

        // Nadie más entra a la administración.
        $this->actingInPanel($a['admin'])->get(ServiceContactRequestResource::getUrl())->assertForbidden();
    }

    public function test_categories_are_managed_without_code_and_reports_reach_the_team(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);
        Livewire::test(ListServiceCategories::class)->callAction('create', ['name' => 'Escaleras mecánicas', 'position' => 5, 'is_active' => true])->assertNotified('Especialidad creada');
        $category = ServiceCategory::where('name', 'Escaleras mecánicas')->sole();
        $this->assertSame('escaleras-mecanicas', $category->slug);
        $this->assertArrayHasKey('escaleras-mecanicas', ServiceDirectory::categoryOptions()->all());
        $this->assertSame(1, PlatformAuditLog::where('action', 'marketplace.service_category.saved')->count());
        $category->forceFill(['is_active' => false])->save();
        $this->assertArrayNotHasKey('escaleras-mecanicas', ServiceDirectory::categoryOptions()->all());

        [, $pro] = $this->makeSupplier();
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        Livewire::test(ServiceRequests::class, ['perfil' => $profile->slug])->set('reportReason', 'misleading')->set('reportDetails', 'Dice ser matriculado y no lo es')->call('report')->assertHasNoErrors();
        Livewire::test(ServiceRequests::class, ['perfil' => $profile->slug])->set('reportReason', 'misleading')->call('report'); // uno abierto por empresa
        $this->assertSame(1, ServiceProfileReport::count());
        $this->assertSame(1, $super->notifications()->where('data->title', 'Reporte de un perfil profesional')->count());
    }

    public function test_erasing_accounts_keeps_requests_without_personal_data_and_no_products_or_orders_change(): void
    {
        $super = User::factory()->superAdmin()->create();
        [$supplier, $pro] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $profile = $this->profileFor($pro);
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $order = app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'pedido');
        $request = app(ServiceContacts::class)->create($a['admin'], $profile, $this->contact(), 'k');
        app(ServiceContacts::class)->reply($a['admin'], $request, 'Datos privados de la empresa A');

        // B tiene perfil propio y recibió una solicitud de A.
        $this->actingInPanel($b['admin']);
        Livewire::test(MyServiceProfile::class)->set('data.description', 'Taller de variadores para otras empresas de mantenimiento.')
            ->set('data.categories', [(string) $this->categoryId()])->set('data.provinces', ['Córdoba'])->call('save')->call('publish');
        $bProfile = ServiceProfile::where('company_id', $b['company']->id)->sole();
        app(ServiceProfiles::class)->approve($super, $bProfile);
        $toB = app(ServiceContacts::class)->create($a['admin'], $bProfile->fresh(), $this->contact(), 'kb');

        app(CompanyEraser::class)->erase($b['company'], $super, $b['company']->name);
        $this->assertNull(Company::find($b['company']->id));
        $bProfile->refresh();
        $this->assertNull($bProfile->company_id);
        $this->assertSame(ServiceProfile::ARCHIVED, $bProfile->status);
        $this->assertSame(ServiceContactRequest::CLOSED, $toB->fresh()->status); // A conserva su historial

        app(CompanyEraser::class)->erase($a['company'], $super, $a['company']->name);
        $request->refresh();
        $this->assertSame([null, 'Empresa dada de baja', ''], [$request->company_id, $request->contact_name, $request->contact_email]);
        $this->assertNull(ServiceContactMessage::where('side', 'company')->value('body'));
        $this->actingAs($pro)->get(route('suppliers.service-requests.show', $request->reference))->assertOk()->assertDontSee('ana@empresa.test')->assertDontSee('Datos privados');

        // Cerrar al proveedor: el perfil queda archivado sin datos; la solicitud y el pedido se conservan.
        auth()->logout();
        $this->actingAs($super);
        app(SupplierEraser::class)->erase($supplier->fresh(), $super, $supplier->name, 'Pedido del proveedor');
        $this->assertSame(ServiceProfile::ARCHIVED, $profile->fresh()->status);
        $this->assertSame('—', $profile->fresh()->description);
        $this->assertNotNull($request->fresh());
        $this->assertNotNull(MarketplaceRequest::find($order->id));
    }
}
