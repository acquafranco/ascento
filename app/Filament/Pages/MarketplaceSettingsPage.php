<?php

namespace App\Filament\Pages;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Services\Activity\PlatformAudit;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\Marketplace\PublicationLimits;
use App\Support\Countries;
use App\Support\Marketplace\SalesReadiness;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración comercial del marketplace (solo SuperAdmin): límites de
 * publicaciones (gratuito / Premium), precio y venta de Premium, países con
 * cobro, regla de visibilidad al volver al plan gratuito y destacados. Sin
 * precio o con la venta apagada no se cobra ni se anuncia nada. Auditado.
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
        return 'Publicar es gratis con un límite; Premium y los destacados son cobros opcionales de Ascento al proveedor, nunca comisiones sobre ventas.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(MarketplaceSettings::current()->only([
            'free_product_limit', 'premium_product_limit', 'premium_enabled', 'premium_price', 'billing_countries', 'over_limit_rule',
            'featured_enabled', 'featured_price_7', 'featured_price_30',
        ]));
    }

    /** Lo que falta en el servidor para poder vender (solo nombres de variables). */
    public function gatewayProblems(): array
    {
        return SalesReadiness::gatewayProblems();
    }

    public function form(Schema $schema): Schema
    {
        $settings = MarketplaceSettings::current();
        $currency = $settings->pricing_currency ?: 'ARS';
        $money = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(0.01)->maxValue(99999999)->prefix($currency)->helperText('Vacío: no se vende.');
        // Solo países con pasarela de cobro y la misma moneda de los precios (hoy, Argentina en ARS).
        $countries = collect(SupplierBilling::countries())->filter(fn ($c) => Supplier::currencyFor($c) === $currency)
            ->mapWithKeys(fn ($c) => [$c => Countries::name($c).' ('.Supplier::currencyFor($c).', Mercado Pago)'])->all();

        return $schema->statePath('data')->components([
            Section::make('Límites de publicaciones')->description('Productos publicados a la vez (no unidades de stock). Borradores, pausados, retirados, ocultos por moderación y fuera del límite no cuentan.')
                ->columns(['default' => 1, 'md' => 3])->schema([
                    TextInput::make('free_product_limit')->label('Plan gratuito')->numeric()->integer()->minValue(1)->maxValue(100000)->required()
                        ->helperText('Gratis para siempre, también para proveedores de otros países.'),
                    TextInput::make('premium_product_limit')->label('Premium')->numeric()->integer()->minValue(1)->maxValue(100000)->required()->gte('free_product_limit'),
                    Select::make('over_limit_rule')->label('Si vuelve al plan gratuito y no eligió')->options(MarketplaceSettings::OVER_LIMIT_RULES)->required()->native(false)
                        ->helperText('Primero quedan las que marcó «conservar» y las que tienen un destacado vigente. Nada se borra.'),
                ]),
            Section::make('Premium (suscripción mensual con Mercado Pago)')->columns(['default' => 1, 'md' => 2])->schema([
                $money('premium_price', 'Precio mensual'),
                Toggle::make('premium_enabled')->label('Vender Premium')->helperText('Apagado o sin precio: no se muestra ni se cobra.'),
            ]),
            Section::make('Cobros por país')->schema([
                CheckboxList::make('billing_countries')->label('Países con cobros habilitados')->options($countries)
                    ->helperText('Los proveedores de los demás países publican gratis y no ven opciones de pago. Para sumar otro país hace falta una pasarela en su moneda.'),
            ]),
            Section::make('Publicaciones destacadas (pago único con Mercado Pago)')->columns(['default' => 1, 'md' => 3])->schema([
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
        $data['billing_countries'] = array_values(array_intersect((array) ($data['billing_countries'] ?? []), SupplierBilling::countries()));

        // Ninguna venta se enciende si falta algo obligatorio (credenciales, precio, país o moneda). Sin valores secretos.
        if ($problems = SalesReadiness::problems($data, MarketplaceSettings::current()->pricing_currency ?: 'ARS')) {
            Notification::make()->title('No se guardó: falta configuración para vender')->body(implode(' ', $problems))->danger()->persistent()->send();

            return;
        }

        $settings = MarketplaceSettings::query()->orderBy('id')->first() ?? new MarketplaceSettings;
        $settings->fill($data + ['pricing_currency' => 'ARS']);
        $settings->forceFill(['updated_by' => Auth::id()])->save();
        PlatformAudit::record('marketplace.settings.updated', $settings, $settings->getChanges(), 'Configuración del marketplace');

        // Si bajó un límite, lo que sobra queda «fuera del límite» ahora (nada se borra; se avisa a cada proveedor).
        $moved = 0;
        if ($settings->wasChanged(['free_product_limit', 'premium_product_limit'])) {
            Supplier::whereNull('closed_at')->each(function (Supplier $s) use (&$moved) {
                $moved += app(PublicationLimits::class)->enforce($s);
            });
        }
        Notification::make()->title('Configuración guardada')->body($moved ? "{$moved} publicaciones quedaron fuera del límite (se avisó a cada proveedor)." : null)->success()->send();
    }
}
