<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SiteVisit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\SiteVisitReminderNotification;
use App\Services\Notifications\Notifier;
use App\Services\WorkOrders\SiteVisits;
use Illuminate\Console\Command;

/**
 * Recordatorios de la visita técnica, una sola vez cada uno (marca en la fila
 * + clave del aviso): llegada sin registrar de un trabajo tomado hace más de
 * ARRIVAL_AFTER_MINUTES, y salida sin registrar de una visita sin trabajos
 * pendientes (o abierta hace más de EXIT_AFTER_HOURS). Con el Notifier de
 * siempre (bandeja + push si el técnico lo activó): sin servicios nuevos.
 */
class SendSiteVisitReminders extends Command
{
    public const ARRIVAL_AFTER_MINUTES = 60;

    /** Visita sin pendientes: minutos desde el último remito. */
    public const EXIT_AFTER_REMITO_MINUTES = 30;

    /** Visita abierta (con o sin pendientes): horas desde la llegada. */
    public const EXIT_AFTER_HOURS = 6;

    protected $signature = 'site-visits:remind';

    protected $description = 'Recuerda al técnico registrar la llegada o finalizar la visita (sin duplicar)';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;

        WorkOrder::withoutGlobalScopes()->where('status', 'in_progress')->whereNull('deleted_at')
            ->whereNull('arrived_at')->whereNull('site_visit_id')->whereNull('arrival_reminded_at')
            ->whereNotNull('started_at')->where('started_at', '<=', now()->subMinutes(self::ARRIVAL_AFTER_MINUTES))
            ->where('started_at', '>=', now()->subDay())
            ->whereDoesntHave('deliveryNote')->with(['users', 'building:id,name'])->orderBy('id')
            ->each(function (WorkOrder $order) use ($notifier, &$sent) {
                // La marca primero (otro proceso no lo repite); la clave del aviso evita el duplicado igual.
                if (! WorkOrder::withoutGlobalScopes()->whereKey($order->id)->whereNull('arrival_reminded_at')->update(['arrival_reminded_at' => now()])) {
                    return;
                }
                $slug = Company::whereKey($order->company_id)->value('slug');
                foreach ($order->users as $user) {
                    $notifier->sendTo($user, new SiteVisitReminderNotification('arrival', $order->building?->name ?? 'el edificio',
                        route('work-orders.show', ['company' => $slug, 'workOrder' => $order->id], false).'#llegada', 'site-visit:arrival:'.$order->id), (int) $order->company_id, mail: false);
                    $sent++;
                }
            });

        SiteVisit::withoutGlobalScopes()->whereNull('closed_at')->whereNull('exit_reminded_at')
            ->where('arrived_at', '>=', now()->subHours(SiteVisits::LEAVE_WINDOW_HOURS))
            ->with('building:id,name')->orderBy('id')
            ->each(function (SiteVisit $visit) use ($notifier, &$sent) {
                if (! $this->exitDue($visit)) {
                    return;
                }
                if (! SiteVisit::withoutGlobalScopes()->whereKey($visit->id)->whereNull('exit_reminded_at')->update(['exit_reminded_at' => now()])) {
                    return;
                }
                $user = User::withoutGlobalScopes()->find($visit->user_id);
                $slug = Company::whereKey($visit->company_id)->value('slug');
                if ($user && $slug) {
                    $notifier->sendTo($user, new SiteVisitReminderNotification('exit', $visit->building?->name ?? 'el edificio',
                        route('dashboard', ['company' => $slug], false), 'site-visit:exit:'.$visit->id), (int) $visit->company_id, mail: false);
                    $sent++;
                }
            });

        $this->info("Recordatorios generados: {$sent}");

        return self::SUCCESS;
    }

    /** Terminó lo de la visita (sin pendientes y el último remito hace un rato) o pasó mucho tiempo. */
    private function exitDue(SiteVisit $visit): bool
    {
        if ($visit->arrived_at->lte(now()->subHours(self::EXIT_AFTER_HOURS))) {
            return true;
        }
        if (SiteVisits::pending($visit)->isNotEmpty()) {
            return false;
        }
        $lastRemito = $visit->buildingVisits()->withoutGlobalScopes()->whereHas('deliveryNote')->max('created_at');

        return $lastRemito !== null && now()->parse($lastRemito)->lte(now()->subMinutes(self::EXIT_AFTER_REMITO_MINUTES));
    }
}
