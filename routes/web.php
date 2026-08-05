<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use App\Models\Company;
use App\Http\Controllers\{
    DashboardController,
    ProfileController,
    ClientController,
    BuildingController,
    WorkOrderController,
    BuildingCheckController,
    TemplateController,
    DeliveryNoteController,
    WhatsAppController,
    ReportController
};

use App\Models\User;
use App\Models\BuildingVisit;


/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/', function () {

    if (auth()->check() && auth()->user()->company) {

        return redirect()->route('dashboard', [
            'company' => auth()->user()->company->slug,
        ]);

    }

    return view('welcome');

});

/*
|--------------------------------------------------------------------------
| PUBLIC DELIVERY NOTE
|--------------------------------------------------------------------------
*/


Route::get(

    '/{company:slug}/public/delivery-notes/{token}',

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

    Route::get('/whatsapp/connect', [
        WhatsAppController::class,
        'connect'
    ])->name('whatsapp.connect');

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

        Route::get('/buildings/all', [
        BuildingController::class,
        'all'
    ])->name('buildings.all');

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
    | REPORTS
    |--------------------------------------------------------------------------
    */

    Route::get('/reports', [
        ReportController::class,
        'index'
    ])->name('reports.index');


    Route::get('/reports/create', [
        ReportController::class,
        'create'
    ])->name('reports.create');

    Route::get('/reports/{report}', [
        ReportController::class,
        'show'
    ])->name('reports.show');

    Route::post('/reports', [
        ReportController::class,
        'store'
    ])->name('reports.store');


    /*
    |--------------------------------------------------------------------------
    | USER TEMPLATE ADMIN
    |--------------------------------------------------------------------------
    */

       Route::middleware('admin')->group(function () {

            Route::get('/users/{user}/template', [
                TemplateController::class,
                'userTemplate',
            ])->name('users.template');

        });
        Route::middleware('admin')->group(function () {

            Route::get('/users/{user}/template/day/{date}', [
                TemplateController::class,
                'userTemplateDay',
            ])->name('users.template.day');

        });


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
        );



        Route::get(
            '/delivery-notes/{deliveryNote}/pdf',
            [
                DeliveryNoteController::class,
                'pdf'
            ]
        )->name('delivery-notes.pdf');


    });


});


Route::get('/whatsapp/callback', [
    WhatsAppController::class,
    'callback'
])->name('whatsapp.callback');

/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

Route::get('/{company:slug}/quote/{token}', function (Company $company, $token) {

    $quote = \App\Models\Quote::where('company_id', $company->id)
        ->where('public_token', $token)
        ->firstOrFail();

    return view('quotes.public', compact('quote'));

})->name('quotes.public');
/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
