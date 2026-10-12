<?php

namespace App\Support\Quotes;

use App\Models\Quote;
use App\Support\CompanyLogo;

/**
 * Datos del presupuesto como documento, compartidos por el PDF
 * (quotes.document) y la vista web responsive (quotes.partials.web): los
 * importes, conceptos y condiciones salen de un solo lugar.
 * Solo muestra los datos de la empresa que estén cargados.
 */
final class QuoteDocument
{
    /** @return array<string, mixed> */
    public static function for(Quote $quote, bool $pdf = false): array
    {
        $company = $quote->company;
        $status = $quote->displayStatus();

        // Solo del disco público y si es una imagen real (ver CompanyLogo).
        $logo = $pdf ? CompanyLogo::dataUri($company) : CompanyLogo::url($company);

        return [
            'company' => $company,
            'status' => $status,
            'status_label' => $quote->displayStatusLabel(),
            'status_style' => match ($status) {
                'approved' => 'background:#E3F4EA;color:#17663A;',
                'rejected', 'void' => 'background:#FDE8E8;color:#9B1C1C;',
                'expired' => 'background:#FFF1E6;color:#A84300;',
                default => 'background:#E8EEFD;color:#1E3A8A;',
            },
            'logo' => $logo,
            'issuer' => array_values(array_filter([
                $company?->business_name && $company->business_name !== $company->name ? $company->business_name : null,
                $company?->cuit ? 'CUIT '.$company->cuit : null,
                $company?->tax_condition,
                trim(implode(', ', array_filter([$company?->address, $company?->city, $company?->province])), ', ') ?: null,
                $company?->phone ? 'Tel. '.$company->phone : null,
                $company?->email,
            ])),
            'building' => $quote->building,
            'bank' => filled($company?->bank_cbu) || filled($company?->bank_alias)
                ? implode(' · ', array_filter([$company->bank_name, $company->bank_cbu ? 'CBU '.$company->bank_cbu : null, $company->bank_alias ? 'Alias '.$company->bank_alias : null]))
                : null,
        ];
    }

    public static function money(mixed $value): string
    {
        return '$ '.number_format((float) $value, 2, ',', '.');
    }

    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}
