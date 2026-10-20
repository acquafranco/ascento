<?php

namespace App\Jobs;

use App\Enums\PermissionArea;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\User;
use App\Notifications\NewReportNotification;
use App\Notifications\ReportLimitReachedNotification;
use App\Notifications\WorkCompletedNotification;
use App\Support\Access\Access;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisa a los administradores de UNA empresa (campanita + push). Corre
 * después de responder al técnico, así nunca lo demora.
 */
class NotifyCompanyAdmins
{
    use Dispatchable;

    public const REPORT = 'report';

    public const WORK_COMPLETED = 'work_completed';

    public const REPORT_LIMIT = 'report_limit';

    public function __construct(public string $event, public int $modelId) {}

    public static function reportCreated(Report $report): void
    {
        static::dispatchAfterResponse(self::REPORT, $report->id);
    }

    /** Una sola vez por mes y por empresa. */
    public static function reportLimitReached(Company $company): void
    {
        $key = 'plan:report-limit-notified:'.$company->id.':'.now()->format('Y-m');

        if (Cache::add($key, true, now()->endOfMonth())) {
            static::dispatchAfterResponse(self::REPORT_LIMIT, $company->id);
        }
    }

    public static function workCompleted(DeliveryNote $deliveryNote): void
    {
        static::dispatchAfterResponse(self::WORK_COMPLETED, $deliveryNote->id);
    }

    public function handle(): void
    {
        [$model, $notification] = match ($this->event) {
            self::REPORT => [$report = Report::withoutGlobalScopes()->with(['building', 'user'])->find($this->modelId), $report ? new NewReportNotification($report) : null],
            self::WORK_COMPLETED => [$note = DeliveryNote::withoutGlobalScopes()->with(['building', 'user', 'workOrder'])->find($this->modelId), $note ? new WorkCompletedNotification($note) : null],
            self::REPORT_LIMIT => [$limitCompany = Company::find($this->modelId), $limitCompany ? new ReportLimitReachedNotification($limitCompany) : null],
            default => [null, null],
        };

        if (! $model || ! $notification) {
            return;
        }

        $company = $model instanceof Company ? $model : Company::find($model->company_id);

        if (! $company || ! $company->hasActiveAccess()) {
            return;
        }

        // Admins de ESA empresa (nunca SuperAdmin ni otra empresa) y, desde la
        // Etapa 6, los administrativos vigentes con el área de ese aviso.
        $admins = User::where('company_id', $company->id)
            ->where('role', 'admin')
            ->where('is_super_admin', false)
            ->get();

        if ($area = $this->staffArea($model)) {
            $admins = $admins->concat(
                User::where('company_id', $company->id)->where('role', User::ROLE_STAFF)->where('is_super_admin', false)->get()
                    ->filter(fn (User $staff) => Access::can($staff, $area))
            );
        }

        // Quien firmó el remito no recibe "Trabajo terminado" de su propio
        // trabajo (un dueño o administrativo que también sale a trabajar): le
        // aparecía como un remito nuevo que no había generado nadie más.
        if ($this->event === self::WORK_COMPLETED) {
            $admins = $admins->reject(fn (User $admin) => (int) $admin->id === (int) $model->user_id);
        }

        foreach ($admins as $admin) {
            try {
                $admin->notify($notification);
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar al admin', ['event' => $this->event, 'user_id' => $admin->id, 'exception' => $e::class]);
            }
        }
    }

    /** Área que tiene que tener un administrativo para recibir el aviso (null: solo el dueño). */
    private function staffArea(mixed $model): ?PermissionArea
    {
        return match ($this->event) {
            self::REPORT => PermissionArea::Inspections,
            self::WORK_COMPLETED => PermissionArea::forJobType($model->assignment_type ?? null),
            default => null, // el cupo del plan es del dueño
        };
    }
}
