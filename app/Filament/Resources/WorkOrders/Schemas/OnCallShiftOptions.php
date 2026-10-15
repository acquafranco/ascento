<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Models\OnCallShift;
use App\Models\WorkOrder;
use App\Services\OnCall\OnCallService;
use App\Support\CompanyContext;

/** Turnos de guardia que se pueden indicar como origen de una orden de trabajo. */
final class OnCallShiftOptions
{
    /** @return array<int, string> */
    public static function for(?WorkOrder $record): array
    {
        $companyId = CompanyContext::currentId();
        if (! $companyId) {
            return [];
        }

        return OnCallShift::query()->where('company_id', $companyId)->active()
            ->where('starts_at', '<=', now()->addDay())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDays(3)))
            ->when($record?->on_call_shift_id, fn ($q, $id) => $q->orWhere('id', $id))
            ->with(['schedule', 'user'])->orderByDesc('starts_at')->limit(60)->get()
            ->mapWithKeys(fn (OnCallShift $s) => [$s->id => $s->label()])->all();
    }

    /** «De guardia ahora: Juan Pérez (Guardia nocturna)». */
    public static function onDutyNow(): ?string
    {
        $companyId = CompanyContext::currentId();
        if (! $companyId) {
            return null;
        }

        $now = collect(app(OnCallService::class)->onDutyAt((int) $companyId, now()))
            ->map(fn ($row) => ($row['shifts']->isEmpty() ? 'sin cobertura' : $row['shifts']->map(fn ($s) => $s->user->name)->implode(', ')).' ('.$row['schedule']->name.')');

        return $now->isEmpty() ? null : 'De guardia ahora: '.$now->implode(' · ').'.';
    }
}
