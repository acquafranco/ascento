<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\User;
use App\Notifications\NewReportNotification;
use App\Notifications\WorkCompletedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
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

    public function __construct(public string $event, public int $modelId) {}

    public static function reportCreated(Report $report): void
    {
        static::dispatchAfterResponse(self::REPORT, $report->id);
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
            default => [null, null],
        };

        if (! $model || ! $notification) {
            return;
        }

        $company = Company::find($model->company_id);

        if (! $company || ! $company->hasActiveAccess()) {
            return;
        }

        // Solo admins de ESA empresa (nunca SuperAdmin ni otra empresa).
        $admins = User::where('company_id', $company->id)
            ->where('role', 'admin')
            ->where('is_super_admin', false)
            ->get();

        foreach ($admins as $admin) {
            try {
                $admin->notify($notification);
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar al admin', ['event' => $this->event, 'user_id' => $admin->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
