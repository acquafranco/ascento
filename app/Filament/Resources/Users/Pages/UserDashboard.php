<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\Page;
use App\Models\User;
use App\Models\BuildingVisit;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;

class UserDashboard extends Page
{
    protected static string $resource = UserResource::class;

    protected string $view =
        'filament.resources.users.pages.user-dashboard';


    public User $record;


    public $weeks = [];


    public $month;

    public $year;



    public function mount(User $record): void
    {
        Carbon::setLocale('es');

        $this->record = $record;

        $this->month = now()->month;

        $this->year = now()->year;


        $this->loadWeeks();
    }



    public function updatedMonth(): void
    {
        $this->loadWeeks();
    }



    public function updatedYear(): void
    {
        $this->loadWeeks();
    }




    private function loadWeeks(): void
    {

        $visits = BuildingVisit::with([
            'building',
            'user',
            'workOrder',
            'deliveryNote',
        ])
        ->where('user_id', $this->record->id)
        ->whereNotNull('visited_at')
        ->whereMonth('visited_at', $this->month)
        ->whereYear('visited_at', $this->year)
        ->orderBy('visited_at')
        ->get();



        $weeks = [];



        $current = Carbon::create(
            $this->year,
            $this->month,
            1
        )->startOfWeek(Carbon::MONDAY);



        $end = Carbon::create(
            $this->year,
            $this->month,
            1
        )
        ->endOfMonth()
        ->endOfWeek(Carbon::SUNDAY);




        while ($current->lte($end)) {


            $weekStart = $current
                ->copy()
                ->startOfDay();



            $weekEnd = $current
                ->copy()
                ->addDays(6)
                ->endOfDay();



            $weekVisits = $visits->filter(function ($visit) use ($weekStart, $weekEnd) {

                return Carbon::parse($visit->visited_at)
                    ->between($weekStart, $weekEnd);

            });



            $weeks[] = [

                'start' => $weekStart,

                'end' => $weekEnd,

                'visits' => $weekVisits,

            ];



            $current->addWeek();

        }



        $this->weeks = $weeks;

    }




    public function render(): View
    {
        return view(
            'filament.resources.users.pages.user-dashboard',
            [
                'weeks' => $this->weeks,
                'month' => $this->month,
                'year' => $this->year,
            ]
        );
    }
}
