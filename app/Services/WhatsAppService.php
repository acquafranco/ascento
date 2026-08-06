<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Company;

class WhatsAppService
{
    public function send(Company $company, string $phone, string $message): bool
    {
        Log::info('WhatsApp send iniciado', [
            'company_id' => $company->id,
            'phone_original' => $phone,
            'message' => $message,
        ]);

        if (empty($company->whatsapp_access_token) || empty($company->whatsapp_phone_number_id)) {
            Log::warning('WhatsApp no configurado para la empresa.', [
                'company_id' => $company->id,
            ]);

            return false;
        }

        $to = $this->normalizePhone($phone);

        Log::info('WhatsApp telefono normalizado', [
            'original' => $phone,
            'normalizado' => $to,
        ]);

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('services.whatsapp.version'),
            $company->whatsapp_phone_number_id,
        );

        Log::info('WhatsApp request', [
            'url' => $url,
            'token_prefix' => substr($company->whatsapp_access_token, 0, 10),
            'api_version' => config('services.whatsapp.version'),
            'payload' => [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ],
        ]);

        try {

            $response = Http::withToken(
                $company->whatsapp_access_token
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
                'successful' => $response->successful(),
                'body' => $response->json(),
                'company_id' => $company->id,
                'phone_number_id' => $company->whatsapp_phone_number_id,
                'request_url' => $url,
                'token_prefix' => substr($company->whatsapp_access_token, 0, 10),
                'sent_to' => $to,
            ]);

            return $response->successful();

        } catch (\Throwable $e) {

            Log::error('WhatsApp error', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }


    public function sendInteractiveButton(Company $company, string $phone, string $message, string $buttonId, string $buttonText): bool
    {
        Log::info('Phone Number ID utilizado', [
    'phone_number_id' => $company->whatsapp_phone_number_id,
]);
        if (empty($company->whatsapp_access_token) || empty($company->whatsapp_phone_number_id)) {
            return false;
        }

        $to = $this->normalizePhone($phone);

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('services.whatsapp.version'),
            $company->whatsapp_phone_number_id,
        );

        $response = Http::withToken($company->whatsapp_access_token)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $message,
                ],
                'action' => [
                    'buttons' => [
                        [
                            'type' => 'reply',
                            'reply' => [
                                'id' => $buttonId,
                                'title' => $buttonText,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Log::info('WhatsApp interactive response', [
            'status' => $response->status(),
            'body' => $response->json(),
            'work_button_id' => $buttonId,
        ]);

        return $response->successful();
    }

    public function sendWorkOrderButton(WorkOrder $workOrder, string $phone): bool
    {
        $company = $workOrder->company;

        if (! $workOrder->building || ! $company || ! $company->whatsapp_connected) {
            Log::warning('No se pudo enviar WorkOrder WhatsApp interactivo', [
                'work_order_id' => $workOrder->id,
                'company_id' => $company?->id,
            ]);

            return false;
        }

        $message =
            "🔧 Nueva orden de trabajo\n\n" .
            "Edificio: {$workOrder->building->name}\n" .
            "Dirección: {$workOrder->building->address}\n" .
            "Unidad: {$workOrder->unit}\n" .
            "Tipo: {$workOrder->type}\n" .
            "Notas: {$workOrder->notes}";

        return $this->sendInteractiveButton(
            $company,
            $phone,
            $message,
            'take_work_order_' . $workOrder->id,
            'Tomar trabajo'
        );
    }
    public function sendFinishWorkOrderButton(WorkOrder $workOrder, string $phone): bool
    {
        $company = $workOrder->company;

        if (! $company || ! $company->whatsapp_connected) {
            return false;
        }

        $message =
            "✅ Trabajo en proceso\n\n" .
            "Edificio: {$workOrder->building->name}\n" .
            "Dirección: {$workOrder->building->address}\n" .
            "Unidad: {$workOrder->unit}\n\n" .
            "Cuando termines el trabajo presioná el botón.";

        return $this->sendInteractiveButton(
            $company,
            $phone,
            $message,
            'finish_work_order_' . $workOrder->id,
            'Finalizar trabajo'
        );
    }
    public function sendFinishWorkOrderLink(WorkOrder $workOrder, string $phone): bool
    {
        $company = $workOrder->company;

        if (! $company || ! $company->whatsapp_connected) {
            return false;
        }

        $url = route('delivery-notes.work-order', $workOrder);

        $message =
            "✅ Ya podés finalizar el trabajo.\n\n" .
            "Abrí el siguiente enlace para completar el remito:\n\n" .
            $url;

        return $this->send(
            $company,
            $phone,
            $message
        );
    }
    public function sendWorkOrder(WorkOrder $workOrder, string $phone): bool
    {
        $company = $workOrder->company;

        if (! $workOrder->building) {
            Log::warning('WorkOrder sin edificio', [
                'work_order_id' => $workOrder->id,
            ]);

            return false;
        }

        if (! $company || ! $company->whatsapp_connected) {
            Log::warning('Empresa sin WhatsApp conectado', [
                'work_order_id' => $workOrder->id,
            ]);

            return false;
        }

        Log::info('Enviando WhatsApp de WorkOrder', [
            'work_order_id' => $workOrder->id,
            'company_id' => $company->id,
            'technician_phone' => $phone,
        ]);

        $message =
            "🔧 Nueva orden de trabajo\n\n" .
            "Edificio: {$workOrder->building->name}\n" .
            "Dirección: {$workOrder->building->address}\n" .
            "Unidad: {$workOrder->unit}\n" .
            "Tipo: {$workOrder->type}\n" .
            "Notas: {$workOrder->notes}";


        return $this->send(
            $company,
            $phone,
            $message
        );
    }


    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
