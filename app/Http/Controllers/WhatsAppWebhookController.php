<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private WorkOrderService $workOrderService,
        private WhatsAppService $whatsAppService
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
        Log::info('================ WEBHOOK DISPARADO ================');

        $payload = $request->all();

        Log::info('WHATSAPP WEBHOOK RECIBIDO', [
            'type' => data_get($payload, 'entry.0.changes.0.value.messages.0.type'),
        ]);

        $message = data_get(
            $payload,
            'entry.0.changes.0.value.messages.0'
        );

        if (! $message) {
            return response()->json([
                'status' => 'ignored_no_message',
            ], 200);
        }

        $phone = data_get($message, 'from');
        $type = data_get($message, 'type');

        if ($type !== 'interactive') {
            return response()->json([
                'status' => 'ignored_not_interactive',
            ], 200);
        }

        $buttonId = data_get(
            $message,
            'interactive.button_reply.id'
        );

        if (! $buttonId || ! $phone) {
            return response()->json([
                'status' => 'ignored_no_button',
            ], 200);
        }

        if (str_starts_with($buttonId, 'take_work_order_')) {
            $workOrderId = str_replace(
                'take_work_order_',
                '',
                $buttonId
            );

            $technician = User::query()
                ->where('phone', $phone)
                ->first();

            $workOrder = WorkOrder::find($workOrderId);

            Log::info('Tomar orden detectado', [
                'work_order_id' => $workOrderId,
                'technician_id' => $technician?->id,
                'work_order_found' => (bool) $workOrder,
            ]);

            if (! $technician || ! $workOrder) {
                Log::warning('No se encontró técnico o trabajo', [
                    'phone' => $phone,
                    'work_order_id' => $workOrderId,
                    'technician_found' => (bool) $technician,
                    'work_order_found' => (bool) $workOrder,
                ]);

                return response()->json([
                    'status' => 'missing_data',
                ], 200);
            }

            if ($workOrder->status !== 'pending') {
                Log::warning('Botón de tomar ignorado: orden ya procesada', [
                    'work_order_id' => $workOrder->id,
                    'status' => $workOrder->status,
                ]);

                return response()->json([
                    'status' => 'ignored',
                    'reason' => 'work_order_not_pending',
                ], 200);
            }

            $this->workOrderService->start(
                $workOrder,
                $technician
            );

            $this->whatsAppService->sendFinishWorkOrderButton(
                $workOrder,
                $technician->phone
            );

            Log::info('ORDEN PASADA A EN PROCESO', [
                'work_order_id' => $workOrder->id,
                'technician_id' => $technician->id,
            ]);

            return response()->json([
                'status' => 'ok',
            ], 200);
        }

        if (str_starts_with($buttonId, 'finish_work_order_')) {
            $workOrderId = str_replace(
                'finish_work_order_',
                '',
                $buttonId
            );

            $technician = User::query()
                ->where('phone', $phone)
                ->first();

            $workOrder = WorkOrder::find($workOrderId);

            if (! $technician || ! $workOrder) {
                Log::warning('No se encontró técnico o trabajo al finalizar', [
                    'phone' => $phone,
                    'work_order_id' => $workOrderId,
                ]);

                return response()->json([
                    'status' => 'missing_data',
                ], 200);
            }

            if ($workOrder->status !== 'in_progress') {
                Log::warning('Botón de finalizar ignorado: orden fuera de estado', [
                    'work_order_id' => $workOrder->id,
                    'status' => $workOrder->status,
                ]);

                return response()->json([
                    'status' => 'ignored',
                    'reason' => 'work_order_not_in_progress',
                ], 200);
            }

            $this->whatsAppService->sendFinishWorkOrderLink(
                $workOrder,
                $technician->phone
            );

            Log::info('LINK DE REMITO ENVIADO', [
                'work_order_id' => $workOrder->id,
                'technician_id' => $technician->id,
            ]);

            return response()->json([
                'status' => 'ok',
            ], 200);
        }

        return response()->json([
            'status' => 'ignored_unknown_button',
        ], 200);
    }
}
