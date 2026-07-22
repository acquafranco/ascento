<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function send(string $phone, string $message): bool
    {
        $to = $this->normalizePhone($phone);

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('services.whatsapp.version'),
            config('services.whatsapp.phone_number_id'),
        );

        $response = Http::withToken(
            config('services.whatsapp.token')
        )->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $message,
            ],
        ]);

        Log::info('WhatsApp response', [
            'status' => $response->status(),
            'body' => $response->json(),
            'from_phone' => $phone,
            'sent_to' => $to,
        ]);

        return $response->successful();
    }


    public function sendWorkOrder(WorkOrder $workOrder): bool
    {
        $technician = $workOrder->technician;

        if (! $technician || ! $technician->phone) {
            return false;
        }

        $message =
            "🔧 Nueva orden de trabajo\n\n" .
            "Edificio: {$workOrder->building->name}\n" .
            "Dirección: {$workOrder->building->address}\n" .
            "Unidad: {$workOrder->unit}\n" .
            "Tipo: {$workOrder->type}\n" .
            "Notas: {$workOrder->notes}";

        return $this->send(
            $technician->phone,
            $message
        );
    }


    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '549')) {
            return '54' . substr($phone, 3);
        }

        if (str_starts_with($phone, '54')) {
            return $phone;
        }

        return '54' . $phone;
    }
}
