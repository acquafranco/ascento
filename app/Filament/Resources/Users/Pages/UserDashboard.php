<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\Page;
use App\Models\User;
use App\Models\BuildingVisit;
use Carbon\Carbon;

class UserDashboard extends Page
{
    protected static string $resource = UserResource::class;

    // 👇 IMPORTANTE: NO static
    protected string $view =
        'filament.resources.users.pages.user-dashboard';

    public User $record;

    public $weeks = [];

    public function mount(User $record): void
    {
        $this->record = $record;

        $visits = BuildingVisit::with(['building', 'user'])
            ->where('user_id', $record->id)
            ->get();

        $start = now()->subDays(30)->startOfWeek();

        $weeks = [];

        for ($w = 0; $w < 6; $w++) {

            $weekStart = $start->copy()->addWeeks($w);
            $weekEnd = $weekStart->copy()->endOfWeek();

            $weeks[] = [
                'start' => $weekStart,
                'end' => $weekEnd,
                'visits' => $visits->filter(
                    fn ($v) =>
                        Carbon::parse($v->visited_at)
                            ->between($weekStart, $weekEnd)
                ),
            ];
        }

        $this->weeks = $weeks;
    }
}
