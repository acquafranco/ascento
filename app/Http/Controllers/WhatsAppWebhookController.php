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
        Log::info('WhatsApp webhook recibido', $request->all());

        $change = data_get($request->all(), 'entry.0.changes.0.value');

        $buttonId = data_get(
            $change,
            'messages.0.interactive.button_reply.id'
        );

        $phone = data_get(
            $change,
            'messages.0.from'
        );

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

            if ($technician && $workOrder) {
                $this->workOrderService->start(
                    $workOrder,
                    $technician
                );

                Log::info('Orden tomada desde WhatsApp', [
                    'work_order_id' => $workOrderId,
                    'technician_id' => $technician->id,
                ]);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
