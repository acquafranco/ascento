<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private WorkOrderService $workOrderService
    ) {
    }

    public function verify(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');

        if ($request->query('hub_verify_token') === $verifyToken) {
            return response($request->query('hub_challenge'), 200);
        }

        return response('Token inválido', 403);
    }

    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('WhatsApp webhook recibido', $payload);

        $message = data_get(
            $payload,
            'entry.0.changes.0.value.messages.0'
        );

        if (! $message) {
            return response()->json(['status' => 'ignored']);
        }

        $phone = data_get($message, 'from');
        $type = data_get($message, 'type');

        $buttonId = null;

        if ($type === 'interactive') {
            $buttonId = data_get(
                $message,
                'interactive.button_reply.id'
            );
        }

        Log::info('WhatsApp mensaje procesado', [
            'type' => $type,
            'phone' => $phone,
            'button_id' => $buttonId,
        ]);

        if (! $buttonId || ! $phone) {
            return response()->json(['status' => 'ignored']);
        }

        if (str_starts_with($buttonId, 'take_work_order_')) {
            $workOrderId = str_replace(
                'take_work_order_',
                '',
                $buttonId
            );

            $technician = User::where('phone', $phone)->first();
            $workOrder = WorkOrder::find($workOrderId);

            Log::info('Datos para iniciar orden desde WhatsApp', [
                'work_order_id' => $workOrderId,
                'technician_id' => $technician?->id,
            ]);

            if (! $technician || ! $workOrder) {
                return response()->json([
                    'status' => 'missing_data'
                ]);
            }

            $this->workOrderService->start(
                $workOrder,
                $technician
            );

            Log::info('Orden tomada desde WhatsApp correctamente', [
                'work_order_id' => $workOrder->id,
                'technician_id' => $technician->id,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
