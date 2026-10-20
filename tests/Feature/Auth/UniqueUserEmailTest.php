<?php

namespace Tests\Feature\Auth;

use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\StaffUsers\Pages\CreateStaffUser;
use App\Filament\Resources\StaffUsers\Pages\EditStaffUser;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\PendingEmailChange;
use App\Services\Onboarding\CompanySetup;
use App\Services\Portal\PortalInvitations;
use App\Services\Staff\StaffManager;
use App\Support\Auth\UserEmail;
use App\Support\Auth\UserEmailConflicts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Un correo = una sola cuenta de Ascento, sea cual sea el rol (dueño,
 * administrativo, técnico, portal, SuperAdmin) o la empresa, sin importar
 * mayúsculas ni espacios, también ante pedidos simultáneos. El mensaje no
 * revela de quién es el correo. El aislamiento entre empresas no cambia.
 */
class UniqueUserEmailTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    private const PASSWORD = 'Kiosco-Verde-8241';

    private array $a;

    private array $b;

    private Client $client;

    private User $portal;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');

        $this->a['technician']->forceFill(['email' => 'tecnico@a.test', 'phone' => '1123456789'])->save();
        $this->a['admin']->forceFill(['email' => 'duena@a.test'])->save();
        $this->a['admin']->markEmailAsVerified(); // puede mandar invitaciones del portal
        $this->b['technician']->forceFill(['email' => 'tecnico@b.test'])->save();

        $this->client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->portal = User::factory()->create(['email' => 'consorcio@cliente.test']);
        $this->portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->client->id])->save();

        $this->staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina', 'email' => 'oficina@a.test', 'password' => self::PASSWORD], ['clients' => 'manage']);
    }

    private function accounts(string $email): int
    {
        return User::withTrashed()->where('email_key', UserEmail::normalize($email))->count();
    }

    private function inviteToPortal(string $email)
    {
        return Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: ['name' => 'Persona', 'email' => $email, 'building_ids' => []]);
    }

    private function createTechnician(string $email)
    {
        return Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Técnico nuevo', 'email' => $email, 'phone' => '11 2345-6789', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->call('create');
    }

    private function createStaff(string $email)
    {
        return Livewire::test(CreateStaffUser::class)
            ->fillForm(['name' => 'Administrativo nuevo', 'email' => $email, 'password' => self::PASSWORD])
            ->call('create');
    }

    private function register(string $email)
    {
        return $this->post('/register', [
            ...$this->registrationProof(),
            'company_name' => 'Ascensores Nuevos', 'name' => 'Dueño Nuevo', 'email' => $email,
            'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123',
            'cuit' => '30-71234567-1', 'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora', 'terms' => '1',
        ]);
    }

    /* 1 y 2: técnico ↔ portal */

    public function test_a_technician_email_cannot_be_used_for_the_portal(): void
    {
        $this->actingInPanel($this->a['admin']);

        // Técnico de la misma empresa y de otra: mismo mensaje, que no dice de quién es.
        foreach (['tecnico@a.test', 'tecnico@b.test'] as $email) {
            $this->inviteToPortal($email)->assertHasTableActionErrors(['email' => [UserEmail::TAKEN_MESSAGE]]);
            $this->assertSame(1, $this->accounts($email));
        }
        $this->assertSame(0, PortalMembership::whereIn('user_id', [$this->a['technician']->id, $this->b['technician']->id])->count());

        // El servicio también lo frena (no depende del formulario).
        $this->expectException(ValidationException::class);
        app(PortalInvitations::class)->invite($this->client, 'X', 'TECNICO@B.test', [], $this->a['admin']);
    }

    public function test_a_portal_email_cannot_be_used_for_a_technician(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->createTechnician('consorcio@cliente.test')->assertHasFormErrors(['email']);
        $this->assertSame(1, $this->accounts('consorcio@cliente.test'));
        $this->assertSame(User::ROLE_CLIENT, $this->portal->fresh()->role);
    }

    /* 3 y 4: todos los roles y empresas */

    public function test_an_owner_email_cannot_be_reused_for_an_administrative_user(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->createStaff('duena@a.test')->assertHasFormErrors(['email']);
        $this->assertSame(1, $this->accounts('duena@a.test'));
        $this->assertSame('admin', $this->a['admin']->fresh()->role);
    }

    public function test_the_rule_applies_to_every_role_and_company_in_every_entry_point(): void
    {
        $super = User::factory()->create(['email' => 'super@ascento.test', 'is_super_admin' => true]);
        $taken = ['duena@a.test', 'oficina@a.test', 'tecnico@a.test', 'tecnico@b.test', 'consorcio@cliente.test', 'super@ascento.test'];

        foreach ($taken as $email) {
            // Panel de la empresa A: técnico, administrativo y portal.
            $this->actingInPanel($this->a['admin']);
            $this->createTechnician($email)->assertHasFormErrors(['email']);
            $this->createStaff($email)->assertHasFormErrors(['email']);
            if ($email !== 'consorcio@cliente.test') { // ese ya es del portal de este cliente: otro mensaje
                $this->inviteToPortal($email)->assertHasTableActionErrors(['email' => [UserEmail::TAKEN_MESSAGE]]);
            }

            // Servicios (asistente inicial y administrativos), sin pasar por el formulario.
            foreach ([
                fn () => app(CompanySetup::class)->createTechnician(Company::factory()->create(), ['name' => 'X', 'email' => $email, 'phone' => '1123456789', 'password' => self::PASSWORD]),
                fn () => app(StaffManager::class)->create($this->a['admin'], ['name' => 'X', 'email' => $email, 'password' => self::PASSWORD]),
            ] as $create) {
                try {
                    $create();
                    $this->fail("Se creó una segunda cuenta con {$email}.");
                } catch (ValidationException $e) {
                    $this->assertSame([UserEmail::TAKEN_MESSAGE], $e->errors()['email']);
                }
            }

            // Registro de una empresa nueva.
            $this->flushSession();
            auth()->logout();
            $this->register($email)->assertSessionHasErrors(['email' => UserEmail::TAKEN_MESSAGE]);

            $this->assertSame(1, $this->accounts($email), "Hay más de una cuenta con {$email}.");
        }
        $this->assertTrue($super->fresh()->is_super_admin);
    }

    /* 5 y 6: mayúsculas y espacios */

    public function test_upper_case_and_accidental_spaces_do_not_make_a_different_email(): void
    {
        $this->actingInPanel($this->a['admin']);

        foreach (['TECNICO@A.TEST', 'Tecnico@a.Test', '  tecnico@a.test  ', "tecnico@a.test\u{00A0}", "\u{200B}Tecnico@A.test"] as $variant) {
            $this->assertTrue(UserEmail::taken($variant), $variant);
            $this->createStaff(trim($variant))->assertHasFormErrors(['email']);
            try {
                app(StaffManager::class)->create($this->a['admin'], ['name' => 'X', 'email' => $variant, 'password' => self::PASSWORD]);
                $this->fail('Se creó una cuenta con '.$variant);
            } catch (ValidationException) {
            }
        }
        $this->inviteToPortal('  TECNICO@a.test ')->assertHasTableActionErrors(['email']);
        $this->assertSame(1, $this->accounts('tecnico@a.test'));

        // Un correo nuevo se guarda siempre igual, venga como venga.
        $this->createTechnician('  Nuevo.Tecnico@A.test ')->assertHasNoFormErrors();
        $this->assertSame('nuevo.tecnico@a.test', User::latest('id')->first()->email);
    }

    /* 7: pedidos simultáneos */

    public function test_the_database_rejects_a_second_account_even_without_the_forms(): void
    {
        $this->assertTrue(UserEmailConflicts::enforced());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->insert(['name' => 'Copia', 'email' => ' TECNICO@a.test', 'password' => 'x', 'role' => 'technician', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_two_simultaneous_requests_cannot_create_two_accounts(): void
    {
        $this->actingInPanel($this->a['admin']);

        // Simula la carrera: el otro pedido crea la cuenta justo después de la validación de este.
        // (En la prueba, esa cuenta entra en la misma transacción y se deshace con ella; lo que
        // importa es que nunca quedan dos cuentas y la persona ve el mensaje de siempre.)
        $raceWinner = function (string $email) {
            User::creating(function (User $user) use ($email) {
                if ($user->email === $email && ! DB::table('users')->where('email', $email)->exists()) {
                    DB::table('users')->insert(['name' => 'Otro pedido', 'email' => $email, 'password' => 'x', 'role' => 'technician', 'created_at' => now(), 'updated_at' => now()]);
                }
            });
        };

        $raceWinner('carrera@portal.test');
        try {
            app(PortalInvitations::class)->invite($this->client, 'Persona', 'carrera@portal.test', [], $this->a['admin']);
            $this->fail('Se creó una segunda cuenta.');
        } catch (ValidationException $e) {
            $this->assertSame([UserEmail::TAKEN_MESSAGE], $e->errors()['email']);
        }
        $this->assertLessThanOrEqual(1, $this->accounts('carrera@portal.test'));

        $raceWinner('carrera@staff.test');
        $this->createStaff('carrera@staff.test')->assertHasFormErrors(['email']);
        $this->assertLessThanOrEqual(1, $this->accounts('carrera@staff.test'));

        $raceWinner('carrera@tecnico.test');
        $this->createTechnician('carrera@tecnico.test')->assertHasFormErrors(['email' => [UserEmail::TAKEN_MESSAGE]]);
        $this->assertLessThanOrEqual(1, $this->accounts('carrera@tecnico.test'));
    }

    /* 8: cambiar el correo */

    public function test_changing_an_email_follows_the_same_rule(): void
    {
        $this->actingInPanel($this->a['admin']);

        // El dueño corrige el correo de un técnico o de un administrativo.
        Livewire::test(EditUser::class, ['record' => $this->a['technician']->getRouteKey()])
            ->fillForm(['email' => 'Consorcio@Cliente.test'])->call('save')->assertHasFormErrors(['email']);
        Livewire::test(EditStaffUser::class, ['record' => $this->staff->getRouteKey()])
            ->fillForm(['email' => 'TECNICO@b.test'])->call('save')->assertHasFormErrors(['email']);
        $this->assertSame(['tecnico@a.test', 'oficina@a.test'], [$this->a['technician']->fresh()->email, $this->staff->fresh()->email]);

        // Su propio correo (perfil): tampoco puede tomar el de otra cuenta.
        foreach ([$this->a['technician'], $this->portal] as $user) {
            try {
                app(PendingEmailChange::class)->request($user, ' DUENA@a.test ');
                $this->fail('Se pidió un cambio a un correo ajeno.');
            } catch (ValidationException $e) {
                $this->assertSame([UserEmail::TAKEN_MESSAGE], $e->errors()['email']);
            }
        }

        // Un cambio legítimo sigue funcionando y queda normalizado.
        Livewire::test(EditUser::class, ['record' => $this->a['technician']->getRouteKey()])
            ->fillForm(['email' => ' Tecnico.Nuevo@A.test'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('tecnico.nuevo@a.test', $this->a['technician']->fresh()->email);
    }

    /* 9 y 10: los usuarios legítimos siguen entrando, recuperando y registrándose */

    public function test_legitimate_users_still_log_in_recover_their_password_and_register(): void
    {
        $this->a['technician']->forceFill(['password' => self::PASSWORD])->save();

        $this->post('/login', ['email' => '  TECNICO@a.test ', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($this->a['technician']);
        auth()->logout();
        $this->flushSession();

        $this->post('/forgot-password', ['email' => 'Tecnico@A.TEST'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->a['technician'], ResetPasswordNotification::class);

        $this->register('Nueva.Duena@Empresa.test')->assertSessionHasNoErrors();
        $this->assertSame('nueva.duena@empresa.test', User::where('role', 'admin')->latest('id')->first()->email);
    }

    public function test_an_existing_portal_account_gets_access_explicitly_instead_of_a_second_account(): void
    {
        // Otra empresa invita a la misma persona del portal: se suma su acceso, no otra cuenta.
        $clientB = Client::factory()->create(['company_id' => $this->b['company']->id]);
        $buildingB = Building::factory()->create(['company_id' => $this->b['company']->id, 'client_id' => $clientB->id]);
        $this->actingInPanel($this->b['admin']);

        $result = app(PortalInvitations::class)->invite($clientB, 'Consorcio', ' CONSORCIO@cliente.test', [$buildingB->id], $this->b['admin']);

        $this->assertTrue($result['existing']);
        $this->assertSame(1, $this->accounts('consorcio@cliente.test'));
        $this->assertSame([$this->a['company']->id, $this->b['company']->id], PortalMembership::where('user_id', $this->portal->id)->orderBy('company_id')->pluck('company_id')->all());
        // No cambia su rol, su empresa de origen ni su contraseña.
        $fresh = $this->portal->fresh();
        $this->assertSame([User::ROLE_CLIENT, $this->a['company']->id, $this->portal->password], [$fresh->role, $fresh->company_id, $fresh->password]);
    }

    /* 11: aislamiento */

    public function test_unique_emails_do_not_open_access_between_companies(): void
    {
        $this->actingInPanel($this->b['admin']);

        // B no puede abrir los accesos al portal de un cliente de A.
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class])->assertForbidden();
        $this->assertSame(0, PortalMembership::where('company_id', $this->b['company']->id)->count());

        // El portal sigue viendo solo los edificios de sus accesos.
        $this->assertSame([], $this->portal->portalBuildings()->where('buildings.company_id', $this->b['company']->id)->pluck('buildings.id')->all());
    }

    /* 12: datos existentes con conflictos */

    public function test_existing_conflicting_accounts_are_reported_and_never_changed(): void
    {
        // Datos de antes: sin el índice nuevo, «ana@x.test» y « Ana@x.test» (con un espacio
        // adelante) podían convivir: el índice único de email no los ve iguales, ni en MySQL.
        $migration = require database_path('migrations/2027_01_12_100000_add_unique_normalized_email_to_users.php');
        $migration->down();
        $first = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => 'ana@x.test']);
        DB::table('users')->insert(['name' => 'Ana portal', 'email' => ' Ana@X.test', 'password' => 'hash-original', 'role' => 'client', 'company_id' => $this->b['company']->id, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('users')->orderBy('id')->get()->map(fn ($u) => (array) $u)->all();

        // La migración no falla ni toca datos: deja un índice común y lo avisa.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'email_key'));
        $this->assertFalse(UserEmailConflicts::enforced());
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->map(fn ($u) => array_diff_key((array) $u, ['email_key' => 1]))->all());

        // El comando los lista (sin el correo completo) y no fuerza el índice.
        $this->assertSame(1, Artisan::call('users:email-conflicts', ['--enforce' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('an***@x.test', $output);
        $this->assertStringNotContainsString('ana@x.test', $output);
        $this->assertSame([['ana@x.test', 2]], collect(UserEmailConflicts::find())->map(fn ($g) => [$g['key'], count($g['users'])])->all());
        $this->assertFalse(UserEmailConflicts::enforced());
        $this->assertSame(2, User::withTrashed()->whereRaw('lower(trim(email)) = ?', ['ana@x.test'])->count());

        // Mientras tanto, nadie puede crear una tercera cuenta con ese correo.
        $this->assertTrue(UserEmail::taken('ANA@x.test'));

        // Resuelto a mano (una persona cambia de correo), el índice se activa.
        $first->forceFill(['email' => 'ana.tecnica@x.test'])->save();
        $this->assertSame(0, Artisan::call('users:email-conflicts', ['--enforce' => true]));
        $this->assertTrue(UserEmailConflicts::enforced());
    }
}
