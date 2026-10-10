<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Quote;
use App\Support\Portal\PortalAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * El presupuesto como documento (web y PDF) para:
 * - el cliente por enlace: solo con un enlace FIRMADO y vigente (lo genera la
 *   empresa al enviarlo; "Renovar enlace" anula los anteriores) y solo si el
 *   presupuesto está emitido (no borradores ni anulados);
 * - el portal (compartido y edificio autorizado);
 * - el admin de la empresa (vista previa y PDF).
 */
class QuoteDocumentController extends Controller
{
    private static function load(Quote $quote): Quote
    {
        return $quote->load([
            'items' => fn ($q) => $q->withoutGlobalScopes(),
            'building' => fn ($q) => $q->withoutGlobalScopes(),
            'building.client' => fn ($q) => $q->withoutGlobalScopes(),
            'client' => fn ($q) => $q->withoutGlobalScopes(),
            'company',
        ]);
    }

    /** Presupuesto del enlace (o null si el enlace no sirve). */
    private function fromLink(Request $request, Company $company, string $token): ?Quote
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $quote = Quote::withoutGlobalScopes()->where('company_id', $company->id)->where('public_token', $token)->first();

        return $quote && $quote->isPubliclyVisible() ? static::load($quote) : null;
    }

    public function public(Request $request, Company $company, string $token)
    {
        $quote = $this->fromLink($request, $company, $token);

        if (! $quote) {
            return response()->view('quotes.link-expired', ['company' => $company], 410);
        }

        return view('quotes.public', [
            'quote' => $quote,
            'pdfUrl' => URL::temporarySignedRoute('quotes.public.pdf', now()->addHours(2), ['company' => $company->slug, 'token' => $token]),
        ]);
    }

    public function publicPdf(Request $request, Company $company, string $token): Response
    {
        $quote = $this->fromLink($request, $company, $token);
        abort_unless($quote, 410);

        return static::pdf($quote);
    }

    public function portalPdf(Request $request, Quote $quote): Response
    {
        PortalAccess::ensureShared($request->user(), $quote, (int) $quote->building_id);

        return static::pdf(static::load($quote));
    }

    /** Admin de la empresa (el scope de empresa ya filtra: otra empresa → 404). */
    public function adminPdf(Request $request, Quote $quote): Response
    {
        abort_unless($request->user()?->isAdmin() || $request->user()?->isSuperAdmin(), 404);

        return static::pdf(static::load($quote));
    }

    public static function pdfContent(Quote $quote): string
    {
        return Pdf::loadView('quotes.pdf', ['quote' => static::load($quote)])->setPaper('a4')->output();
    }

    private static function pdf(Quote $quote): Response
    {
        return response(static::pdfContent($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="presupuesto-'.$quote->numberLabel().'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
