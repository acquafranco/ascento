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

    Log::info('HEADERS', $request->headers->all());

    Log::info('RAW BODY', [
        'body' => $request->getContent(),
    ]);

    $payload = $request->all();

    Log::info('PAYLOAD', $payload);

    $message = data_get(
        $payload,
        'entry.0.changes.0.value.messages.0'
    );

    Log::info('MESSAGE', [
        'message' => $message,
    ]);

    if (! $message) {

        Log::warning('No llegó ningún mensaje');

        return response()->json([
            'status' => 'ignored_no_message'
        ]);
    }

    $phone = data_get($message, 'from');
    $type = data_get($message, 'type');

    Log::info('TIPO MENSAJE', [
        'type' => $type,
        'phone' => $phone,
    ]);

    $buttonId = null;

    if ($type === 'interactive') {
        $buttonId = data_get(
            $message,
            'interactive.button_reply.id'
        );
    }

    Log::info('BOTON', [
        'button_id' => $buttonId,
    ]);

    if (! $buttonId || ! $phone) {

        Log::warning('No llegó button_reply', [
            'type' => $type,
            'phone' => $phone,
        ]);

        return response()->json([
            'status' => 'ignored_no_button'
        ]);
    }

    if (str_starts_with($buttonId, 'take_work_order_')) {

        $workOrderId = str_replace(
            'take_work_order_',
            '',
            $buttonId
        );

        Log::info('Tomar orden detectado', [
            'work_order_id' => $workOrderId,
        ]);

        $technician = User::where('phone', $phone)->first();
        $workOrder = WorkOrder::find($workOrderId);

        Log::info('Datos encontrados', [
            'technician' => $technician?->id,
            'work_order' => $workOrder?->id,
        ]);

        if (! $technician || ! $workOrder) {

            Log::error('No se encontró técnico o trabajo');

            return response()->json([
                'status' => 'missing_data'
            ]);
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
    }

    if (str_starts_with($buttonId, 'finish_work_order_')) {

        $workOrderId = str_replace(
            'finish_work_order_',
            '',
            $buttonId
        );

        $technician = User::where('phone', $phone)->first();
        $workOrder = WorkOrder::find($workOrderId);

        if (! $technician || ! $workOrder) {
            return response()->json([
                'status' => 'missing_data'
            ]);
        }

        $this->whatsAppService->sendFinishWorkOrderLink(
            $workOrder,
            $technician->phone
        );

        Log::info('LINK DE REMITO ENVIADO', [
            'work_order_id' => $workOrder->id,
            'technician_id' => $technician->id,
        ]);
    }

    return response()->json([
        'status' => 'ok'
    ]);
}
}
