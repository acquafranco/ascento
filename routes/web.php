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


/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/', function(){

    if(auth()->check() && auth()->user()->company){

        return redirect()->route('dashboard', [
            'company' => auth()->user()->company->slug
        ]);

    }

    return redirect()->route('login');

});

/*
|--------------------------------------------------------------------------
| PUBLIC DELIVERY NOTE
|--------------------------------------------------------------------------
*/


Route::get(
    '/{company:slug}/public/delivery-notes/{deliveryNote}',
    [DeliveryNoteController::class, 'showPublic']
)->name('delivery-notes.public');


/*
|--------------------------------------------------------------------------
| COMPANY APPLICATION
|--------------------------------------------------------------------------
*/

Route::prefix('{company:slug}')
    ->middleware([
        'auth',
        'company',
        'company.defaults',
    ])
    ->scopeBindings()
    ->group(function () {


    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [
        DashboardController::class,
        'index'
    ])->name('dashboard');


    /*
    |--------------------------------------------------------------------------
    | PROFILE
    |--------------------------------------------------------------------------
    */

    Route::controller(ProfileController::class)->group(function () {

        Route::get('/profile', 'edit')
            ->name('profile.edit');

        Route::patch('/profile', 'update')
            ->name('profile.update');

        Route::delete('/profile', 'destroy')
            ->name('profile.destroy');

    });


    /*
    |--------------------------------------------------------------------------
    | BUILDINGS
    |--------------------------------------------------------------------------
    */

    Route::get('/buildings', [
        BuildingController::class,
        'index'
    ])->name('buildings.index');


    Route::get('/buildings/{building}', [
        BuildingController::class,
        'show'
    ])->name('buildings.show');



    /*
    |--------------------------------------------------------------------------
    | TEMPLATES
    |--------------------------------------------------------------------------
    */

    Route::get('/my-templates', [
        TemplateController::class,
        'index'
    ])->name('templates.index');


    Route::get('/my-templates/day/{date}', [
        TemplateController::class,
        'day'
    ])->name('templates.day');



    /*
    |--------------------------------------------------------------------------
    | USER TEMPLATE ADMIN
    |--------------------------------------------------------------------------
    */

    Route::get('/users/{user}/template', function(User $user){

        abort_unless(
            Gate::forUser(auth()->user())
                ->allows('view-user-template',$user),
            403
        );


        $month = request('month', now()->month);
        $year  = request('year', now()->year);


        $visits = BuildingVisit::with([
                'building',
                'user'
            ])
            ->where('user_id',$user->id)
            ->whereNotNull('visited_at')
            ->whereMonth('visited_at',$month)
            ->whereYear('visited_at',$year)
            ->orderBy('visited_at')
            ->get();


        $weeks=[];


        $current=\Carbon\Carbon::create(
            $year,
            $month,
            1
        )->startOfWeek(
            \Carbon\Carbon::MONDAY
        );


        $end=\Carbon\Carbon::create(
            $year,
            $month,
            1
        )
        ->endOfMonth()
        ->endOfWeek(
            \Carbon\Carbon::SUNDAY
        );


        while($current->lte($end)){


            $start=$current->copy()->startOfDay();
            $finish=$current->copy()
                ->addDays(6)
                ->endOfDay();


            $weeks[]=[
                'start'=>$start,
                'end'=>$finish,
                'visits'=>$visits->filter(
                    fn($v)=>
                    \Carbon\Carbon::parse(
                        $v->visited_at
                    )->between($start,$finish)
                )
            ];


            $current->addWeek();

        }


        return view(
            'admin.user-template',
            compact(
                'user',
                'weeks',
                'month',
                'year'
            )
        );


    })->name('users.template');



    /*
    |--------------------------------------------------------------------------
    | WORK ORDERS
    |--------------------------------------------------------------------------
    */


    Route::get('/work-orders',[
        WorkOrderController::class,
        'index'
    ])->name('work-orders.index');


    Route::post('/work-orders/{workOrder}/start',[
        WorkOrderController::class,
        'start'
    ])->name('work-orders.start');


    Route::post('/work-orders/{workOrder}/finish',[
        WorkOrderController::class,
        'finish'
    ])->name('work-orders.finish');



    /*
    |--------------------------------------------------------------------------
    | BUILDING CHECK
    |--------------------------------------------------------------------------
    */

    Route::post('/building-check/{building}/done',[
        BuildingCheckController::class,
        'done'
    ])->name('building-check.done');


    Route::post('/building-check/{building}/failed',[
        BuildingCheckController::class,
        'failed'
    ])->name('building-check.failed');



    /*
    |--------------------------------------------------------------------------
    | DELIVERY NOTES
    |--------------------------------------------------------------------------
    */


    Route::get('/delivery-notes',[
        DeliveryNoteController::class,
        'index'
    ])->name('delivery-notes.index');


    Route::get('/delivery-notes/create/building/{building}',[
        DeliveryNoteController::class,
        'createFromBuilding'
    ])->name('delivery-notes.building');


    Route::get('/delivery-notes/create/work-order/{workOrder}',[
        DeliveryNoteController::class,
        'createFromWorkOrder'
    ])->name('delivery-notes.work-order');


    Route::post('/delivery-notes/store',[
        DeliveryNoteController::class,
        'store'
    ])->name('delivery-notes.store');


    Route::get('/delivery-notes/{deliveryNote}',[
    DeliveryNoteController::class,
    'show'
    ])
    ->scopeBindings()
    ->name('delivery-notes.show');


    /*
    |--------------------------------------------------------------------------
    | ADMIN
    |--------------------------------------------------------------------------
    */


    Route::middleware('admin')->group(function(){


        Route::resource(
            'buildings',
            BuildingController::class
        )->except([
            'index',
            'show'
        ]);


        Route::resource(
            'clients',
            ClientController::class
        )->except([
            'index',
            'show'
        ]);



        Route::get(
            '/delivery-notes/{deliveryNote}/pdf',
            [
                DeliveryNoteController::class,
                'pdf'
            ]
        )->name('delivery-notes.pdf');


    });


});



/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

Route::get('/quote/{token}', function($token){

    $quote = \App\Models\Quote::where(
        'public_token',
        $token
    )->firstOrFail();


    return view(
        'quotes.public',
        compact('quote')
    );


})->name('quotes.public');



/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
