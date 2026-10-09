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
    ReportController,
    ReportPhotoController,
    PushSubscriptionController
};

use App\Models\User;
use App\Models\BuildingVisit;


/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/', function () {

    // Logueado: a la pantalla de su rol (panel para admins, app para técnicos).
    if (auth()->check() && ($home = auth()->user()->homeUrl())) {
        return redirect()->to($home);
    }

    return view('welcome');

});

Route::get('/legal/terminos', fn () => view('legal.terms'))->name('legal.terms');
Route::get('/legal/privacidad', fn () => view('legal.privacy'))->name('legal.privacy');
Route::get('/legal/eliminacion-de-datos', fn () => view('legal.data-deletion'))->name('legal.data-deletion');

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
        // Sin suscripción/trial vigente no se opera (técnicos ni admins).
        'subscription',
    ])
    ->scopeBindings()
    ->group(function () {

    Route::get('/whatsapp/connect', [
        WhatsAppController::class,
        'connect'
    ])->middleware('admin')->name('whatsapp.connect');

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
    ])->where('date', '\d{4}-\d{2}-\d{2}')->name('templates.day');



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
            ])->where('date', '\d{4}-\d{2}-\d{2}')->name('users.template.day');

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

    // Detalle de una orden: destino del push "Nueva orden de trabajo".
    // withTrashed: una orden eliminada (cancelada) muestra un aviso claro
    // en vez de un 404 (el controller decide qué se ve).
    Route::get('/work-orders/{workOrder}', [
        WorkOrderController::class,
        'show'
    ])->whereNumber('workOrder')->withTrashed()->name('work-orders.show');

    /*
    |--------------------------------------------------------------------------
    | NOTIFICACIONES PUSH (técnicos)
    |--------------------------------------------------------------------------
    */

    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('push-subscriptions.store');

    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('push-subscriptions.destroy');

    Route::post('/push-subscriptions/test', [PushSubscriptionController::class, 'test'])
        ->middleware('throttle:3,1')
        ->name('push-subscriptions.test');

    // Telegram: canal adicional de avisos.
    Route::get('/telegram/connect', [\App\Http\Controllers\TelegramLinkController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('telegram.connect');

    Route::delete('/telegram', [\App\Http\Controllers\TelegramLinkController::class, 'disconnect'])
        ->name('telegram.disconnect');


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


        // El CRUD de edificios y clientes vive en Filament (/admin).
        // Acá solo quedan las vistas de lectura que existen realmente.
        Route::resource(
            'clients',
            ClientController::class
        )->only([
            'index',
            'show',
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
| ARCHIVOS PRIVADOS
|--------------------------------------------------------------------------
| Las fotos de reportes viven en el disco privado: solo se descargan por
| acá, con sesión y permisos (ver ReportPhotoController).
*/

// Con la suscripción/prueba vencida, igual que el resto de la app: el admin
// va a la pantalla de suscripción y el técnico ve el aviso.
Route::middleware(['auth', 'subscription'])->whereNumber(['report', 'photo'])->group(function () {
    // Link viejo (una sola foto): la primera.
    Route::get('/files/reports/{report}/photo', [ReportPhotoController::class, 'first'])->name('reports.photo');
    Route::get('/files/reports/{report}/photos/{photo}', [ReportPhotoController::class, 'show'])->name('reports.photos.show');
    Route::get('/files/reports/{report}/pdf', \App\Http\Controllers\ReportPdfController::class)->name('reports.pdf');
});

// Exportación de datos de la empresa (ZIP privado): solo admins de la
// empresa; cada descarga queda registrada (ver CompanyExportDownloadController).
Route::get('/files/exports/{companyExport}', \App\Http\Controllers\CompanyExportDownloadController::class)
    ->middleware(['auth', 'subscription', 'throttle:20,1'])
    ->whereNumber('companyExport')
    ->name('company-exports.download');

Route::get('/files/elevator-documents/{elevatorDocument}', \App\Http\Controllers\ElevatorDocumentController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('elevatorDocument')
    ->name('elevator-documents.show');

Route::get('/whatsapp/callback', [
    WhatsAppController::class,
    'callback'
])->middleware('auth')->name('whatsapp.callback');

/*
|--------------------------------------------------------------------------
| PORTAL DEL CLIENTE (consorcios / administraciones)
|--------------------------------------------------------------------------
| Solo lectura de lo que la empresa compartió, de los edificios autorizados
| (ver PortalAccess). Login con la pantalla común.
*/

Route::prefix('portal')->name('portal.')->middleware(['auth', 'portal'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Portal\PortalController::class, 'home'])->name('home');
    Route::get('/edificios/{building}', [\App\Http\Controllers\Portal\PortalController::class, 'building'])->whereNumber('building')->name('building');
    Route::get('/remitos/{deliveryNote}', [\App\Http\Controllers\Portal\PortalController::class, 'deliveryNote'])->name('delivery-note');
    Route::get('/reportes/{report}', [\App\Http\Controllers\Portal\PortalController::class, 'report'])->whereNumber('report')->name('report');
    Route::get('/reportes/{report}/fotos/{photo}', [\App\Http\Controllers\Portal\PortalController::class, 'reportPhoto'])->whereNumber(['report', 'photo'])->name('report-photo');
    Route::get('/presupuestos/{quote}', [\App\Http\Controllers\Portal\PortalController::class, 'quote'])->whereNumber('quote')->name('quote');
    Route::get('/documentos/{elevatorDocument}', [\App\Http\Controllers\Portal\PortalController::class, 'document'])->whereNumber('elevatorDocument')->name('document');
    Route::get('/notificaciones', [\App\Http\Controllers\NotificationInboxController::class, 'index'])->name('notifications');
    Route::get('/notificaciones/contador', [\App\Http\Controllers\NotificationInboxController::class, 'count'])->middleware('throttle:60,1')->name('notifications.count');
    Route::get('/notificaciones/{notification}', [\App\Http\Controllers\NotificationInboxController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('/notificaciones/leidas', [\App\Http\Controllers\NotificationInboxController::class, 'readAll'])->name('notifications.read-all');
});

// Bandeja de avisos de los técnicos (los admins usan la campanita del panel).
Route::middleware(['auth', 'subscription'])->prefix('notificaciones')->name('notifications.')->group(function () {
    Route::get('/', [\App\Http\Controllers\NotificationInboxController::class, 'index'])->name('index');
    Route::get('/contador', [\App\Http\Controllers\NotificationInboxController::class, 'count'])->middleware('throttle:60,1')->name('count');
    Route::get('/{notification}', [\App\Http\Controllers\NotificationInboxController::class, 'open'])->whereUuid('notification')->name('open');
    Route::post('/leidas', [\App\Http\Controllers\NotificationInboxController::class, 'readAll'])->name('read-all');
});

// Ingreso y activación del portal (sin sesión). La activación usa el token
// de la invitación: de un solo uso, vence a las 72 h.
Route::middleware('guest')->prefix('portal')->name('portal.')->group(function () {
    Route::get('/ingresar', fn () => view('auth.login', ['portal' => true]))->name('login');
    Route::get('/activar/{token}', [\App\Http\Controllers\Portal\PortalInvitationController::class, 'show'])->middleware('throttle:20,1')->name('invitation');
    Route::post('/activar', [\App\Http\Controllers\Portal\PortalInvitationController::class, 'store'])->middleware('throttle:6,1')->name('invitation.store');
});

/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

Route::get('/{company:slug}/quote/{token}', function (Company $company, $token) {

    // Link público (lo abre el cliente): empresa + token. Sin el scope de la
    // sesión: si en el navegador hay otra cuenta logueada, igual abre.
    $quote = \App\Models\Quote::withoutGlobalScopes()
        ->with([
            'items' => fn ($q) => $q->withoutGlobalScopes(),
            'building' => fn ($q) => $q->withoutGlobalScopes(),
            'client' => fn ($q) => $q->withoutGlobalScopes(),
            'company',
        ])
        ->where('company_id', $company->id)
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
