<?php

namespace App\Services\Quotes;

use App\Enums\PlanFeature;
use App\Http\Controllers\QuoteDocumentController;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteSentNotification;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envío de un presupuesto por correo desde Ascento (no desde el mail del
 * admin): resumen, total, vencimiento, botón con enlace firmado y el PDF
 * adjunto. Un borrador pasa a "Enviado"; queda registrado en el historial.
 */
class QuoteSender
{
    /**
     * @return bool true si el proveedor de correo aceptó el envío
     *
     * @throws ValidationException
     */
    public function send(Quote $quote, string $email, ?string $message, ?User $user = null): bool
    {
        $company = Company::findOrFail($quote->company_id);
        PlanGuard::for($company)->ensureFeature(PlanFeature::Quotes);

        if (! in_array($quote->status, [Quote::DRAFT, Quote::SENT, 'pending'], true)) {
            throw ValidationException::withMessages(['email' => 'Solo se envían presupuestos en borrador o enviados. Para un presupuesto cerrado, duplicalo.']);
        }

        if ($quote->items()->count() === 0 && (float) $quote->amount <= 0) {
            throw ValidationException::withMessages(['email' => 'El presupuesto no tiene ítems ni importe.']);
        }

        // Al enviarlo pasa a "Enviado" (si era borrador): el enlace lo exige.
        if ($quote->status !== Quote::SENT) {
            $quote->forceFill(['status' => Quote::SENT])->save();
        }

        $url = $quote->signedPublicUrl();

        try {
            $pdf = QuoteDocumentController::pdfContent($quote);
        } catch (Throwable $e) {
            Log::warning('No se pudo generar el PDF del presupuesto', ['quote_id' => $quote->id, 'exception' => $e::class]);
            $pdf = null; // se manda igual, con el enlace
        }

        try {
            Notification::route('mail', $email)->notifyNow(new QuoteSentNotification($quote, $company, $url, $pdf, $message));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el presupuesto por correo', ['quote_id' => $quote->id, 'exception' => $e::class]);

            return false;
        }

        $quote->forceFill(['sent_at' => now(), 'sent_to' => Str::limit($email, 250, '')])->saveQuietly();
        $quote->log('sent', 'A '.$email, $user);

        return true;
    }

    /** Anula los enlaces enviados antes (cambia el token). */
    public function revokeLinks(Quote $quote, ?User $user = null): void
    {
        $quote->forceFill(['public_token' => (string) Str::uuid()])->saveQuietly();
        $quote->log('link_revoked', null, $user);
    }
}
