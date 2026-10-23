<?php

namespace App\Support\Billing;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\SubscriptionPlan;
use App\Support\Marketplace\Money;
use App\Support\Marketplace\SalesReadiness;

/**
 * Estado de los tres cobros de Ascento con Mercado Pago, para el SuperAdmin
 * (sin valores secretos: solo nombres de variables y lo que falta).
 *
 * 1. Suscripciones de empresas de mantenimiento (preapproval; los tres planes
 *    de SubscriptionPlan). Ya operan: se bloquea un alta nueva solo si falta
 *    el token o si es de prueba (TEST-) en producción. Lo demás (clave del
 *    webhook, cuenta sin verificar) se avisa: sin webhook, los pagos se
 *    confirman igual por la API («Actualizar estado» y la conciliación
 *    automática cada 6 horas).
 * 2. Premium de proveedores (preapproval aparte, tablas propias).
 * 3. Destacados de 7 y 30 días (pago único, Checkout Pro).
 *    2 y 3 no empiezan un cobro nuevo si falta CUALQUIER cosa
 *    (SalesReadiness::gatewayProblems, que incluye la cuenta verificada).
 */
final class BillingStatus
{
    /** @return list<string> lo que impide empezar una suscripción NUEVA de empresa */
    public static function companyBlockers(): array
    {
        $token = (string) config('services.mercadopago.access_token');

        return match (true) {
            $token === '' => ['Falta MERCADOPAGO_ACCESS_TOKEN.'],
            app()->isProduction() && str_starts_with($token, 'TEST-') => ['MERCADOPAGO_ACCESS_TOKEN es una credencial de prueba (TEST-) y la aplicación está en producción.'],
            default => [],
        };
    }

    public static function companyCanCharge(): bool
    {
        return self::companyBlockers() === [];
    }

    /** @return list<string> avisos que no bloquean las suscripciones de empresas */
    public static function companyWarnings(): array
    {
        if (! self::companyCanCharge()) {
            return [];
        }
        $warnings = [];
        if (blank(config('services.mercadopago.webhook_secret'))) {
            $warnings[] = app()->isProduction()
                ? 'Falta MERCADOPAGO_WEBHOOK_SECRET: en producción se rechazan los avisos de Mercado Pago y cada pago se confirma recién con «Actualizar estado» o con la conciliación cada 6 horas.'
                : 'Falta MERCADOPAGO_WEBHOOK_SECRET (fuera de producción los avisos se aceptan sin firma; el estado igual se consulta a la API).';
        }
        if (app()->isProduction() && ! str_starts_with((string) config('app.url'), 'https://')) {
            $warnings[] = 'APP_URL no empieza con https://.';
        }

        return array_merge($warnings, MercadoPagoAccount::problems());
    }

    /**
     * @return array<string, array{status: string, ok: bool, problems: list<string>, warnings: list<string>}>
     */
    public static function flows(): array
    {
        $settings = MarketplaceSettings::current();
        $gateway = SalesReadiness::gatewayProblems();
        $money = fn ($v) => Money::format((float) $v, $settings->pricing_currency ?: 'ARS');
        $plans = SubscriptionPlan::offered();

        $companyBlockers = self::companyBlockers();

        $premiumStatus = match (true) {
            ! $settings->premium_enabled => 'Apagado: no se ofrece.',
            ! $settings->sellsPremium() => 'Encendido sin precio: no se ofrece.',
            $gateway !== [] => 'Encendido, pero no se ofrece hasta completar lo que falta.',
            default => 'Se ofrece a '.$money($settings->premium_price).' por mes.',
        };
        $featuredStatus = match (true) {
            ! $settings->featured_enabled => 'Apagados: no se ofrecen.',
            $settings->featuredOffers() === [] => 'Encendidos sin precio: no se ofrecen.',
            $gateway !== [] => 'Encendidos, pero no se ofrecen hasta completar lo que falta.',
            default => 'Se ofrecen: '.collect($settings->featuredOffers())->map(fn ($p, $d) => $d.' días a '.$money($p))->implode(', ').'.',
        };

        return [
            'Suscripciones de empresas' => [
                'status' => $companyBlockers === []
                    ? 'Se pueden contratar: '.$plans->map(fn (SubscriptionPlan $p) => $p->name.' '.$p->formattedPrice())->implode(' · ').'.'
                    : 'No se pueden empezar suscripciones nuevas.',
                'ok' => $companyBlockers === [],
                'problems' => $companyBlockers,
                'warnings' => self::companyWarnings(),
            ],
            'Premium de proveedores' => ['status' => $premiumStatus, 'ok' => $gateway === [] && $settings->sellsPremium(), 'problems' => $settings->premium_enabled ? $gateway : [], 'warnings' => []],
            'Destacados (7 y 30 días)' => ['status' => $featuredStatus, 'ok' => $gateway === [] && $settings->featuredOffers() !== [], 'problems' => $settings->featured_enabled ? $gateway : [], 'warnings' => []],
        ];
    }
}
