<?php

namespace App\Support\DeliveryNotes;

use App\Models\DeliveryNote;
use Illuminate\Support\Str;

/**
 * Enlaces para compartir un remito desde el panel: los mismos que usa el
 * técnico (delivery-notes.show). El enlace público lleva el token del remito
 * y muestra solo lo que puede ver el cliente (DeliveryNoteDocument).
 */
final class DeliveryNoteShare
{
    public static function canShare(DeliveryNote $note): bool
    {
        return filled($note->public_token) && filled($note->company?->slug);
    }

    /**
     * Remitos muy viejos no tienen token: se le crea uno al pedir compartirlo
     * (no cambia nada del documento firmado).
     */
    public static function ensureToken(DeliveryNote $note): void
    {
        if (blank($note->public_token)) {
            $note->forceFill(['public_token' => (string) Str::uuid()]);
            // Sin tocar updated_at: el remito firmado no se modifica, solo se le crea el enlace.
            DeliveryNote::withoutTimestamps(fn () => $note->saveQuietly());
        }
    }

    public static function publicUrl(DeliveryNote $note): string
    {
        return route('delivery-notes.public', ['company' => $note->company->slug, 'token' => $note->public_token]);
    }

    public static function publicPdfUrl(DeliveryNote $note): string
    {
        return route('delivery-notes.public.pdf', ['company' => $note->company->slug, 'token' => $note->public_token]);
    }

    /** PDF para el panel (dueño / administrativo con Documentos). */
    public static function pdfUrl(DeliveryNote $note, bool $download = false): string
    {
        return route('delivery-notes.pdf', array_filter([
            'company' => $note->company?->slug,
            'deliveryNote' => $note,
            'descargar' => $download ? 1 : null,
        ]));
    }

    public static function number(DeliveryNote $note): string
    {
        return str_pad((string) $note->number, 6, '0', STR_PAD_LEFT);
    }

    /** WhatsApp: elige el contacto quien comparte (no se adivina el número). */
    public static function whatsappUrl(DeliveryNote $note): string
    {
        $text = "📄 *Remito de servicio*\nNúmero: #".self::number($note)
            ."\nFecha: ".$note->created_at?->format('d/m/Y')
            .($note->building ? "\nEdificio: ".trim($note->building->name.' '.$note->building->address) : '')
            ."\n\n🔗 Ver remito:\n".self::publicUrl($note);

        return 'https://wa.me/?text='.rawurlencode($text);
    }

    /** Correo: con el email del cliente ya cargado, si lo tiene. */
    public static function mailUrl(DeliveryNote $note): string
    {
        $to = (string) preg_replace('/[^\w.@+-]/u', '', (string) $note->building?->client?->email);
        $body = "REMITO DE SERVICIO\n\nNúmero: #".self::number($note)
            ."\nFecha: ".$note->created_at?->format('d/m/Y')
            ."\nEstado: ".($note->performed ? 'Realizado' : 'No realizado')
            ."\n\nVer remito:\n".self::publicUrl($note)
            ."\n\nDescargar PDF:\n".self::publicPdfUrl($note)."\n";

        return 'mailto:'.$to.'?subject='.rawurlencode('Remito #'.self::number($note).' · '.$note->company?->name).'&body='.rawurlencode($body);
    }
}
