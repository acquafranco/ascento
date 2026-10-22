<?php

namespace App\Filament\Pages;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Services\Activity\PlatformAudit;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\MercadoPagoService;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración comercial del marketplace (solo SuperAdmin): duración de la
 * prueba, precio de la suscripción de proveedores, cobros habilitados,
 * modalidad gratuita fuera de Argentina y precios de destacados. Sin precios
 * cargados no se cobra ni se anuncia nada. Cada cambio queda auditado.
 */
class MarketplaceSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Configuración del marketplace';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'marketplace/configuracion';

    protected static ?string $title = 'Configuración del marketplace';

    protected string $view = 'filament.pages.marketplace-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Prueba, suscripción y destacados de los proveedores de repuestos. Son cobros de Ascento al proveedor: nunca comisiones sobre ventas.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(MarketplaceSettings::current()->only([
            'trial_days', 'billing_enabled', 'plan_price', 'international_free', 'featured_enabled', 'featured_price_7', 'featured_price_30',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(0.01)->maxValue(99999999)->prefix('ARS')->helperText('Vacío: no se vende.');

        return $schema->statePath('data')->components([
            Section::make('Prueba gratuita')->schema([
                TextInput::make('trial_days')->label('Días de prueba')->numeric()->integer()->minValue(1)->maxValue(365)->required()
                    ->helperText('Para proveedores aprobados de acá en adelante. Empieza una sola vez, al aprobar la cuenta; cambiarlo no modifica pruebas ya iniciadas.'),
            ]),
            Section::make('Suscripción de proveedores (Argentina, Mercado Pago)')->columns(['default' => 1, 'md' => 2])->schema([
                $money('plan_price', 'Precio mensual'),
                Toggle::make('billing_enabled')->label('Cobrar la suscripción')
                    ->helperText('Apagado: los proveedores argentinos siguen sin cargo al terminar la prueba. Encendido (con precio): al terminar la prueba, para seguir publicando necesitan la suscripción activa.'),
            ]),
            Section::make('Otros países')->schema([
                Toggle::make('international_free')->label('Modalidad gratuita para proveedores de otros países')
                    ->helperText('Esta etapa no tiene cobros fuera de Argentina. Encendido: operan sin cargo. Apagado: al terminar la prueba su catálogo deja de verse (no pueden pagar).'),
            ]),
            Section::make('Publicaciones destacadas (pago único, Mercado Pago)')->columns(['default' => 1, 'md' => 3])->schema([
                $money('featured_price_7', 'Precio por 7 días'),
                $money('featured_price_30', 'Precio por 30 días'),
                Toggle::make('featured_enabled')->label('Vender destacados'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        if (($data['billing_enabled'] ?? false) && blank($data['plan_price'] ?? null)) {
            Notification::make()->title('Falta el precio')->body('Para cobrar la suscripción, cargá el precio mensual.')->warning()->send();

            return;
        }
        if ((($data['billing_enabled'] ?? false) || ($data['featured_enabled'] ?? false)) && ! MercadoPagoService::isConfigured()) {
            Notification::make()->title('Mercado Pago no está configurado')->body('Sin MERCADOPAGO_ACCESS_TOKEN no se puede cobrar: dejá los cobros apagados.')->danger()->send();

            return;
        }
        if (($data['featured_enabled'] ?? false) && blank($data['featured_price_7'] ?? null) && blank($data['featured_price_30'] ?? null)) {
            Notification::make()->title('Falta el precio')->body('Para vender destacados, cargá al menos un precio.')->warning()->send();

            return;
        }

        $settings = MarketplaceSettings::query()->orderBy('id')->first() ?? new MarketplaceSettings;
        $wasCharging = $settings->exists && $settings->chargesSubscription();
        $settings->fill($data + ['plan_currency' => 'ARS']);
        $settings->forceFill(['updated_by' => Auth::id()])->save();
        PlatformAudit::record('marketplace.settings.updated', $settings, $settings->getChanges(), 'Configuración del marketplace');

        $body = null;
        if (! $wasCharging && $settings->chargesSubscription()) {
            $affected = Supplier::where('status', Supplier::APPROVED)->whereNull('closed_at')->whereIn('country', SupplierBilling::countries())
                ->where(fn ($q) => $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now()))
                ->whereDoesntHave('subscription', fn ($q) => $q->where('current_period_end', '>', now()))->count();
            $body = $affected ? "{$affected} proveedor(es) con la prueba terminada y sin suscripción dejan de verse hasta suscribirse (se les avisa en la próxima corrida de marketplace:tick)." : null;
        }
        Notification::make()->title('Configuración guardada')->body($body)->success()->send();
    }
}
