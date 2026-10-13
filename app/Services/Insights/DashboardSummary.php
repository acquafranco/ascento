<?php

namespace App\Services\Insights;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\ClientInteraction;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\AreaGate;
use App\Support\CompanyContext;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Facades\DB;

/**
 * Datos del inicio del panel (dueño y administrativos). Cada bloque
 * (tarjeta, serie de un gráfico, línea del seguimiento) declara sus áreas y
 * se consulta SOLO si quien mira las tiene todas (AreaGate): lo que no puede
 * ver no se calcula ni viaja a la página (tampoco en los datos de los
 * gráficos, de donde salen los tooltips). Reutiliza los cálculos de los
 * módulos (scopes de Quote, Receivable, Task y ClientInteraction, la
 * evolución de Indicators y las alertas del Centro de atención). Cada widget
 * pide solo su bloque: no hay consultas repetidas entre widgets.
 */
class DashboardSummary
{
    public function __construct(
        private Indicators $indicators,
        private AttentionCenter $attention,
    ) {}

    /** Enlace a un módulo solo si quien mira puede abrirlo (nunca un 403). */
    private function url(string $target, array $parameters = []): ?string
    {
        return $target::canAccess() ? $target::getUrl('index', $parameters) : null;
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }

    /**
     * Tarjetas de «Hoy» (7 como máximo, en este orden).
     *
     * @return list<array{key: string, label: string, value: string, description: string, icon: string, color: string, url: ?string}>
     */
    public function cards(?User $user): array
    {
        $gate = AreaGate::for($user);
        $cards = [];

        if ($gate->allows(Area::WorkOrders)) {
            $pending = WorkOrder::where('status', 'pending')->count();
            $inProgress = WorkOrder::where('status', 'in_progress')->count();
            $today = WorkOrder::whereDate('created_at', today())->count();
            $url = $this->url(WorkOrderResource::class);

            // Abiertas, sin importar el día en que se cargaron; las en curso, en el detalle.
            $cards[] = ['key' => 'orders', 'label' => 'Órdenes pendientes', 'value' => (string) $pending,
                'description' => $inProgress.' en proceso · '.($today === 1 ? '1 cargada hoy' : $today.' cargadas hoy'),
                'icon' => 'heroicon-m-clock', 'color' => 'warning', 'url' => $url];
            // Por la fecha de finalización; las «no realizadas» no cuentan.
            $cards[] = ['key' => 'completed_today', 'label' => 'Terminadas hoy', 'value' => (string) WorkOrder::where('status', 'completed')->whereDate('finished_at', today())->count(),
                'description' => 'Órdenes completadas', 'icon' => 'heroicon-m-check-circle', 'color' => 'success', 'url' => $url];
        }

        if ($gate->allows(Area::Documents)) {
            $cards[] = ['key' => 'notes_today', 'label' => 'Remitos de hoy', 'value' => (string) DeliveryNote::whereDate('created_at', today())->count(),
                'description' => today()->format('d/m/Y'), 'icon' => 'heroicon-m-document-text', 'color' => 'success', 'url' => $this->url(DeliveryNoteResource::class)];
        }

        if ($gate->allows(Area::Quotes)) {
            // Misma regla que Indicadores y la ficha del cliente; los borradores, aparte.
            $awaiting = Quote::awaitingResponse();
            $drafts = Quote::open()->where('status', Quote::DRAFT)->count();
            $count = (clone $awaiting)->count();
            $cards[] = ['key' => 'quotes', 'label' => 'Presupuestos esperando respuesta', 'value' => (string) $count,
                'description' => $this->money((float) (clone $awaiting)->sum('amount')).' · '.($drafts === 1 ? '1 borrador' : $drafts.' borradores').' sin enviar',
                'icon' => 'heroicon-m-currency-dollar', 'color' => 'info', 'url' => $this->url(QuoteResource::class)];
        }

        if ($gate->allows(Area::Receivables)) {
            $overdue = Receivable::overdue();
            $cards[] = ['key' => 'receivables', 'label' => 'Cobros vencidos', 'value' => $this->money((float) (clone $overdue)->sum(DB::raw('amount - paid_amount'))),
                'description' => ((clone $overdue)->count()).' con saldo pendiente', 'icon' => 'heroicon-m-banknotes', 'color' => 'danger', 'url' => $this->url(ReceivableResource::class)];
        }

        if ($gate->allows(Area::Buildings)) {
            $machines = (int) Building::sum(DB::raw('elevator_count + freight_elevator_count'));
            $cards[] = ['key' => 'buildings', 'label' => 'Edificios', 'value' => (string) Building::count(),
                'description' => $machines.' máquinas instaladas', 'icon' => 'heroicon-m-building-office-2', 'color' => 'primary', 'url' => $this->url(BuildingResource::class)];
        }

        // Quién está ocupado sale de las órdenes en curso: pide las dos áreas.
        if ($gate->allows(Area::Technicians, Area::WorkOrders)) {
            $busy = WorkOrder::where('status', 'in_progress')->with(['users', 'participants'])->get()
                ->flatMap(fn (WorkOrder $order) => $order->users->merge($order->participants))->unique('id')->count();
            // User no tiene scope global de empresa: el filtro va explícito.
            $total = User::where('company_id', CompanyContext::currentId())->whereNotIn('role', ['admin', 'client', 'staff'])->count();
            $cards[] = ['key' => 'technicians', 'label' => 'Técnicos ocupados', 'value' => $busy.' de '.$total,
                'description' => max(0, $total - $busy).' disponibles', 'icon' => 'heroicon-m-user-group', 'color' => 'warning', 'url' => $this->url(UserResource::class)];
        }

        return $cards;
    }

    /**
     * Trabajo de los últimos 6 meses (la evolución de Indicadores, mismas
     * áreas por serie). Solo con «Indicadores de empresa» en el plan.
     *
     * @return array{labels: list<string>, series: array<string, list<int|float|null>>}|null
     */
    public function workTrend(Company $company, ?User $user): ?array
    {
        if (! PlanGuard::for($company)->allows(PlanFeature::CompanyIndicators)) {
            return null;
        }

        $rows = $this->indicators->for($user)->evolution();
        if ($rows === null) {
            return null;
        }

        $series = [];
        foreach (['completed', 'claims', 'maintenance'] as $key) {
            if (array_key_exists($key, $rows->first())) {
                $series[$key] = $rows->pluck($key)->all();
            }
        }

        return $series === [] ? null : ['labels' => $rows->pluck('month')->all(), 'series' => $series];
    }

    /**
     * Presupuestos creados en los últimos 90 días, por estado. Borradores
     * vigentes y anulados no entran.
     *
     * @return array<string, int>|null
     */
    public function quotesBreakdown(Company $company, ?User $user): ?array
    {
        if (! PlanGuard::for($company)->allows(PlanFeature::Quotes) || ! AreaGate::for($user)->allows(Area::Quotes)) {
            return null;
        }

        $quotes = Quote::where('created_at', '>=', now()->subDays(90))
            ->whereIn('status', [Quote::DRAFT, Quote::SENT, Quote::APPROVED, Quote::REJECTED])
            ->get(['id', 'status', 'valid_until']);

        return [
            'awaiting' => $quotes->filter(fn (Quote $q) => $q->status === Quote::SENT && ! $q->isExpired())->count(),
            'approved' => $quotes->where('status', Quote::APPROVED)->count(),
            'rejected' => $quotes->where('status', Quote::REJECTED)->count(),
            'expired' => $quotes->filter(fn (Quote $q) => $q->isExpired())->count(),
        ];
    }

    /**
     * Seguimiento comercial: tareas, contactos, presupuestos por vencer y las
     * 3 alertas principales del Centro de atención (ya filtradas por áreas).
     *
     * @return array{tasks: ?array, contacts: ?array, expiring: ?array, alerts: ?array}
     */
    public function followUp(Company $company, ?User $user): array
    {
        $gate = AreaGate::for($user);
        $plan = PlanGuard::for($company);

        $tasks = null;
        if ($plan->allows(PlanFeature::Tasks) && $gate->allows(Area::Tasks)) {
            $tasks = [
                'overdue' => Task::overdue()->count(),
                'open' => Task::open()->count(),
                'mine' => $user ? Task::open()->where('assigned_to', $user->id)->count() : 0,
                'url' => $this->url(TaskResource::class, ['tab' => 'overdue']),
            ];
        }

        $contacts = $plan->allows(PlanFeature::ClientContacts) && $gate->allows(Area::Contacts) ? [
            'count' => ClientInteraction::needsFollowUp()->count(),
            'days' => ClientInteraction::FOLLOW_UP_DAYS,
            'url' => $this->url(ClientInteractionResource::class),
        ] : null;

        $expiring = $plan->allows(PlanFeature::Quotes) && $gate->allows(Area::Quotes) ? [
            'count' => Quote::expiringWithin(5)->count(),
            'url' => $this->url(QuoteResource::class),
        ] : null;

        $alerts = null;
        if ($plan->allows(PlanFeature::AttentionCenter) && $gate->allows(Area::Attention)) {
            $items = $this->attention->visibleTo($user, $this->attention->basic($company));
            $alerts = [
                'items' => $items->take(3)->map(fn (array $i) => array_intersect_key($i, array_flip(['severity', 'title', 'count', 'url'])))->all(),
                'more' => max(0, $items->count() - 3),
                'url' => AttentionCenterPage::canAccess() ? AttentionCenterPage::getUrl() : null,
            ];
        }

        return compact('tasks', 'contacts', 'expiring', 'alerts');
    }

    /**
     * ¿Ve algún bloque del inicio? (para el aviso de un administrativo sin
     * nada). Solo permisos y plan, sin consultar datos.
     */
    public function hasAnyBlock(Company $company, ?User $user): bool
    {
        $gate = AreaGate::for($user);
        $plan = PlanGuard::for($company);

        return $gate->allows(Area::WorkOrders) || $gate->allows(Area::Documents) || $gate->allows(Area::Quotes)
            || $gate->allows(Area::Receivables) || $gate->allows(Area::Buildings)
            || ($gate->allows(Area::Tasks) && $plan->allows(PlanFeature::Tasks))
            || ($gate->allows(Area::Contacts) && $plan->allows(PlanFeature::ClientContacts))
            // Solo la línea de mantenimientos del gráfico (plan con indicadores de empresa).
            || ($gate->allows(Area::Visits) && $plan->allows(PlanFeature::CompanyIndicators))
            || ($gate->allows(Area::Attention) && $plan->allows(PlanFeature::AttentionCenter));
    }
}
