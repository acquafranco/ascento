<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;

use App\Http\Controllers\{
    DashboardController,
    ProfileController,
    ClientController,
    BuildingController,
    WorkOrderController,
    BuildingCheckController,
    TemplateController,
    DeliveryNoteController
};

use App\Models\User;
use App\Models\BuildingVisit;
use App\Models\Quote;

/*
|--------------------------------------------------------------------------
| ROOT
|--------------------------------------------------------------------------
*/

Route::get('/', fn () =>
    redirect()->route('dashboard')
);

Route::get(
    '/public/delivery-notes/{deliveryNote}',
    [DeliveryNoteController::class, 'showPublic']
)->name('delivery-notes.public');
/*
|--------------------------------------------------------------------------
| 🔐 AUTH MIDDLEWARE GLOBAL
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | PROFILE
    |--------------------------------------------------------------------------
    */

    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | BUILDINGS
    |--------------------------------------------------------------------------
    */

    Route::get('/buildings', [BuildingController::class, 'index'])
        ->name('buildings.index');

    Route::get('/buildings/{building}', [BuildingController::class, 'show'])
        ->name('buildings.show');

    /*
    |--------------------------------------------------------------------------
    | TEMPLATE GENERAL
    |--------------------------------------------------------------------------
    */

    Route::get('/my-templates', [TemplateController::class, 'index'])
        ->name('templates.index');

    Route::get('/my-templates/day/{date}',[TemplateController::class, 'day']
        )->name('templates.day');
    /*
    |--------------------------------------------------------------------------
    | 🔒 TEMPLATE POR USUARIO (CORRECTO + CERRADO)
    |--------------------------------------------------------------------------
    */

    Route::get('/users/{user}/template', function (User $user) {

         abort_unless(
            Gate::forUser(auth()->user())->allows('view-user-template', $user),
            403,
            'Solo admin puede ver esto'
        );

        // 📦 DATA
        $visits = BuildingVisit::with(['building', 'user'])
            ->where('user_id', $user->id)
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
                        \Carbon\Carbon::parse($v->visited_at)
                            ->between($weekStart, $weekEnd)
                ),
            ];
        }

        return view('admin.user-template', [
            'user' => $user,
            'weeks' => $weeks,
            'month' => request('month', now()->month),
            'year' => request('year', now()->year),
        ]);

    })->name('users.template');

    /*
    |--------------------------------------------------------------------------
    | WORK ORDERS
    |--------------------------------------------------------------------------
    */

    Route::get('/work-orders', [WorkOrderController::class, 'index'])
        ->name('work-orders.index');

    Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])
        ->name('work-orders.start');

    Route::post('/work-orders/{workOrder}/finish', [WorkOrderController::class, 'finish'])
        ->name('work-orders.finish');

    /*
    |--------------------------------------------------------------------------
    | BUILDING CHECK
    |--------------------------------------------------------------------------
    */

    Route::post('/building-check/{building}/done', [BuildingCheckController::class, 'done'])
        ->name('building-check.done');

    Route::post('/building-check/{building}/failed', [BuildingCheckController::class, 'failed'])
        ->name('building-check.failed');

    Route::get(
        '/delivery-notes/create/building/{building}',
        [DeliveryNoteController::class, 'createFromBuilding']
    )->name('delivery-notes.building');

    Route::get(
        '/delivery-notes/create/work-order/{workOrder}',
        [DeliveryNoteController::class, 'createFromWorkOrder']
    )->name('delivery-notes.work-order');

    Route::post(
        '/delivery-notes/store',
        [DeliveryNoteController::class, 'store']
    )->name('delivery-notes.store');

    Route::get(
    '/delivery-notes/{deliveryNote}',
    [DeliveryNoteController::class, 'show']
    )->name('delivery-notes.show');

    Route::get(
    '/delivery-notes',
    [DeliveryNoteController::class, 'index']
    )->name('delivery-notes.index');


    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY
    |--------------------------------------------------------------------------
    */

    Route::middleware('admin')->group(function () {

        Route::resource('buildings', BuildingController::class)
            ->except(['index', 'show']);

        Route::resource('clients', ClientController::class)
            ->except(['index', 'show']);

           Route::get(
            '/delivery-notes/{deliveryNote}/pdf',
            [DeliveryNoteController::class, 'pdf']
            )->name('delivery-notes.pdf');
            });

});
Route::get('/quote/{token}', function ($token) {

    $quote = \App\Models\Quote::where('public_token', $token)
        ->firstOrFail();

    return view('quotes.public', compact('quote'));

})->name('quotes.public');



/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/


require __DIR__.'/auth.php';
