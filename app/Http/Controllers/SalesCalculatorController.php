<?php

namespace App\Http\Controllers;

use App\Models\SalesLead;
use App\Models\User;
use App\Notifications\App\SalesLeadNotification;
use App\Services\Sales\CalculatorInput;
use App\Services\Sales\ImplementationQuote;
use App\Services\Sales\PlanAdvisor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Calculadora comercial de la Welcome: qué plan necesita una empresa (con los
 * planes y límites reales) y cuánto costaría que Ascento le cargue los datos
 * para empezar. Sin pedir datos personales para ver el resultado.
 *
 * - show(): la página completa (sin JavaScript, o para compartir el enlace).
 * - result(): el resultado como fragmento HTML (la Welcome lo pide sin recargar).
 * - lead(): pedido de implementación o de propuesta personalizada; el
 *   presupuesto se recalcula acá (nunca se toma el del navegador) y un
 *   reenvío del mismo formulario no crea otro pedido.
 */
class SalesCalculatorController extends Controller
{
    public function __construct(private PlanAdvisor $advisor, private ImplementationQuote $quotes) {}

    public function show(Request $request): Response
    {
        $data = ['old' => $request->query()];
        if ($request->filled('edificios')) {
            try {
                $data += $this->result($request->query());
            } catch (ValidationException $e) {
                $data['errors'] = $e->validator->errors();
            }
        }

        return response()->view('sales.calculator-page', $data)->header('X-Robots-Tag', 'noindex');
    }

    public function fragment(Request $request): Response
    {
        try {
            $data = $this->result($request->query());
        } catch (ValidationException $e) {
            return response()->view('sales.partials.errors', ['errors' => $e->validator->errors()], 422);
        }

        return response()->view('sales.partials.result', $data);
    }

    /** @throws ValidationException */
    private function result(array $query): array
    {
        $input = CalculatorInput::fromRequest($query);

        return [
            'input' => $input,
            'advice' => $this->advisor->advise($input),
            'quote' => $this->quotes->quote($input),
            'leadKey' => (string) Str::uuid(),
        ];
    }

    public function lead(Request $request): Response
    {
        // Robot: el campo trampa vino lleno. Respuesta genérica; nada se guarda.
        if (filled($request->input('sitio_web'))) {
            return response()->view('sales.partials.lead-done', ['email' => null], 422);
        }

        $input = CalculatorInput::fromRequest($request->all());
        $data = Validator::make($request->all(), [
            'tipo' => ['required', 'in:'.implode(',', array_keys(SalesLead::TYPES))],
            'k' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]{6,40}$/'],
            'empresa' => ['nullable', 'string', 'max:160'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
        ], [
            'nombre.required' => 'Decinos cómo te llamás.',
            'email.required' => 'Necesitamos un correo para responderte.',
            'email.email' => 'Revisá el correo.',
            'telefono.regex' => 'Revisá el teléfono.',
        ])->validate();

        $advice = $this->advisor->advise($input);
        $plan = $request->input('plan');
        $plan = collect($advice['plans'])->firstWhere('slug', $plan) ? $plan : $advice['recommended'];

        try {
            $lead = SalesLead::firstOrCreate(['idempotency_key' => hash('sha256', 'lead|'.$data['k'])], [
                'type' => $data['tipo'],
                'status' => 'new',
                'inputs' => $input->toArray(),
                'plan_slug' => $plan,
                'quote' => $this->quotes->quote($input),
                'name' => trim($data['nombre']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => filled($data['telefono'] ?? null) ? trim($data['telefono']) : null,
                'company_name' => filled($data['empresa'] ?? null) ? trim($data['empresa']) : null,
                'message' => filled($data['mensaje'] ?? null) ? trim($data['mensaje']) : null,
                'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $lead = SalesLead::where('idempotency_key', hash('sha256', 'lead|'.$data['k']))->firstOrFail();
        }

        if ($lead->wasRecentlyCreated) {
            $this->notifyTeam($lead);
        }

        // Con JavaScript, el aviso reemplaza al formulario; sin JavaScript, una página.
        return response()->view($request->ajax() ? 'sales.partials.lead-done' : 'sales.lead-page', ['email' => $lead->email]);
    }

    /** Aviso al equipo de Ascento (campanita de los SuperAdmin). Si falla, el pedido igual queda. */
    private function notifyTeam(SalesLead $lead): void
    {
        $notification = new SalesLeadNotification($lead->id, SalesLead::TYPES[$lead->type], (string) ($lead->company_name ?: $lead->name));
        User::where('is_super_admin', true)->get()->each(function (User $super) use ($notification, $lead) {
            try {
                $super->notifyNow($notification);
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar un pedido comercial', ['sales_lead_id' => $lead->id, 'exception' => $e::class]);
            }
        });
    }
}
