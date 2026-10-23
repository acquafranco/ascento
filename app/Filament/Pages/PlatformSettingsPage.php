<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use App\Services\Activity\PlatformAudit;
use App\Services\MercadoPagoApiException;
use App\Support\Billing\BillingStatus;
use App\Support\Billing\MercadoPagoAccount;
use App\Support\Countries;
use App\Support\RegistrationCountries;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Configuración general de la plataforma (solo SuperAdmin):
 *
 * - Registro por país (RegistrationCountries): el switch internacional y los
 *   países habilitados uno por uno. Nunca cambia cuentas existentes ni
 *   habilita cobros.
 * - Estado de los cobros con Mercado Pago (BillingStatus) y verificación de
 *   la cuenta (MercadoPagoAccount, solo lectura). Sin valores secretos.
 *
 * Todo cambio queda en la auditoría de plataforma.
 */
class PlatformSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuración de la plataforma';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'plataforma/configuracion';

    protected static ?string $title = 'Configuración de la plataforma';

    protected string $view = 'filament.pages.platform-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Registro por país y estado de los cobros con Mercado Pago. Ningún cambio de esta página modifica cuentas existentes ni activa pagos.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = PlatformSettings::current();
        $this->form->fill([
            'international_registration_enabled' => (bool) $settings->international_registration_enabled,
            'registration_countries' => RegistrationCountries::configuredExtra(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $others = collect(Countries::options())->except(RegistrationCountries::HOME)->all();

        return $schema->statePath('data')->components([
            Section::make('Registro por país')
                ->description('Argentina siempre está habilitada. Las cuentas que ya existen, de cualquier país, no cambian.')
                ->schema([
                    Toggle::make('international_registration_enabled')
                        ->label('Permitir registros de otros países de Latinoamérica')
                        ->helperText('Permite que nuevas empresas de mantenimiento y nuevos proveedores del marketplace se registren desde los países habilitados. No modifica las cuentas existentes ni activa automáticamente los pagos internacionales.'),
                    CheckboxList::make('registration_countries')
                        ->label('Países habilitados, además de Argentina')
                        ->options($others)
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                        ->helperText('Solo cuentan con el switch encendido. Sin países marcados, el registro sigue siendo solo de Argentina. Habilitar un país no habilita cobros ni facturación ahí: Premium, destacados y suscripciones siguen solo en los países con pasarela.'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        $settings = PlatformSettings::row();
        $settings->forceFill([
            'international_registration_enabled' => (bool) ($data['international_registration_enabled'] ?? false),
            // Solo códigos de Latinoamérica conocidos; Argentina no se guarda (siempre está).
            'registration_countries' => RegistrationCountries::clean((array) ($data['registration_countries'] ?? [])),
            'updated_by' => Auth::id(),
        ])->save();

        PlatformAudit::record('platform.registration.updated', $settings, [
            'internacional' => $settings->international_registration_enabled ? 'sí' : 'no',
            'paises' => implode(',', (array) $settings->registration_countries),
        ], 'Registro por país');

        $this->form->fill([
            'international_registration_enabled' => (bool) $settings->international_registration_enabled,
            'registration_countries' => RegistrationCountries::configuredExtra(),
        ]);

        Notification::make()->title('Configuración guardada')
            ->body('Hoy se aceptan registros nuevos de: '.implode(', ', RegistrationCountries::options()).'.')->success()->send();
    }

    /** Países donde hoy se acepta un registro nuevo (lo guardado, no lo que se está editando). */
    public function enabledCountries(): array
    {
        return RegistrationCountries::options();
    }

    /** @return array<string, array{status: string, ok: bool, problems: list<string>, warnings: list<string>}> */
    public function billingFlows(): array
    {
        return BillingStatus::flows();
    }

    public function accountMode(): string
    {
        return MercadoPagoAccount::modeLabel();
    }

    public function accountCheckedAt(): ?string
    {
        return (MercadoPagoAccount::verification()['checked_at'] ?? null)?->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    /** Consulta a Mercado Pago si la cuenta es de prueba o real (/users/me: no cobra ni crea nada). */
    public function verifyAccount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (MercadoPagoAccount::fingerprint() === null) {
            Notification::make()->title('Falta MERCADOPAGO_ACCESS_TOKEN en el servidor')->warning()->send();

            return;
        }
        if (! RateLimiter::attempt('platform:mp-verify:'.Auth::id(), 5, fn () => true, 60)) {
            Notification::make()->title('Esperá un minuto antes de volver a verificar')->warning()->send();

            return;
        }

        try {
            MercadoPagoAccount::verify();
        } catch (MercadoPagoApiException $e) {
            Notification::make()->title('Mercado Pago rechazó la verificación')->body($e->hint())->danger()->persistent()->send();

            return;
        } catch (Throwable $e) {
            Log::warning('No se pudo verificar la cuenta de Mercado Pago', ['exception' => $e::class]);
            Notification::make()->title('No se pudo conectar con Mercado Pago')->body('Intentá de nuevo en unos minutos.')->danger()->send();

            return;
        }

        Notification::make()->title('Cuenta verificada')->body(MercadoPagoAccount::modeLabel().'.')->success()->send();
    }
}
