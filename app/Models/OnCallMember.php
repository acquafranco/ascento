<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Participante de una guardia rotativa, en su orden (con exclusión temporal opcional). */
class OnCallMember extends Model
{
    use BelongsToCompany;

    protected $fillable = ['on_call_schedule_id', 'user_id', 'position', 'excluded_from', 'excluded_until'];

    protected $casts = [
        'position' => 'integer',
        'excluded_from' => 'date',
        'excluded_until' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'on_call_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** ¿Está excluido en algún momento de [$from, $to)? */
    public function isExcludedBetween(CarbonInterface $from, CarbonInterface $to): bool
    {
        if (! $this->excluded_from && ! $this->excluded_until) {
            return false;
        }

        $exFrom = $this->excluded_from?->copy()->startOfDay();
        $exTo = $this->excluded_until?->copy()->addDay()->startOfDay();

        return (! $exFrom || $exFrom->lt($to)) && (! $exTo || $exTo->gt($from));
    }
}
