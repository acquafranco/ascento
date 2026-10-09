<?php

use App\Http\Controllers\BuildingCheckController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyExportDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\ElevatorDocumentController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ReportPhotoController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WorkOrderController;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
            'connect',
        ])->middleware('admin')->name('whatsapp.connect');

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [
            DashboardController::class,
            'index',
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
            'all',
        ])->name('buildings.all');

        Route::get('/buildings', [
            BuildingController::class,
            'index',
        ])->name('buildings.index');

        /*
        |--------------------------------------------------------------------------
        | TEMPLATES
        |--------------------------------------------------------------------------
        */

        Route::get('/my-templates', [
            TemplateController::class,
            'index',
        ])->name('templates.index');

        Route::get('/my-templates/day/{date}', [
            TemplateController::class,
            'day',
        ])->where('date', '\d{4}-\d{2}-\d{2}')->name('templates.day');

        /*
        |--------------------------------------------------------------------------
        | REPORTS
        |--------------------------------------------------------------------------
        */

        Route::get('/reports', [
            ReportController::class,
            'index',
        ])->name('reports.index');

        Route::get('/reports/create', [
            ReportController::class,
            'create',
        ])->name('reports.create');

        Route::get('/reports/{report}', [
            ReportController::class,
            'show',
        ])->name('reports.show');

        Route::post('/reports', [
            ReportController::class,
            'store',
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

        Route::get('/work-orders', [
            WorkOrderController::class,
            'index',
        ])->name('work-orders.index');

        // Detalle de una orden: destino del push "Nueva orden de trabajo".
        // withTrashed: una orden eliminada (cancelada) muestra un aviso claro
        // en vez de un 404 (el controller decide qué se ve).
        Route::get('/work-orders/{workOrder}', [
            WorkOrderController::class,
            'show',
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
        Route::get('/telegram/connect', [TelegramLinkController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->name('telegram.connect');

        Route::delete('/telegram', [TelegramLinkController::class, 'disconnect'])
            ->name('telegram.disconnect');

        Route::post('/work-orders/{workOrder}/start', [
            WorkOrderController::class,
            'start',
        ])->name('work-orders.start');

        Route::post('/work-orders/{workOrder}/finish', [
            WorkOrderController::class,
            'finish',
        ])->name('work-orders.finish');

        /*
        |--------------------------------------------------------------------------
        | BUILDING CHECK
        |--------------------------------------------------------------------------
        */

        Route::post('/building-check/{building}/done', [
            BuildingCheckController::class,
            'done',
        ])->name('building-check.done');

        /*
        |--------------------------------------------------------------------------
        | DELIVERY NOTES
        |--------------------------------------------------------------------------
        */

        Route::get('/delivery-notes', [
            DeliveryNoteController::class,
            'index',
        ])->name('delivery-notes.index');

        Route::get('/delivery-notes/create/building/{building}', [
            DeliveryNoteController::class,
            'createFromBuilding',
        ])->name('delivery-notes.building');

        Route::get('/delivery-notes/create/work-order/{workOrder}', [
            DeliveryNoteController::class,
            'createFromWorkOrder',
        ])->name('delivery-notes.work-order');

        Route::post('/delivery-notes/store', [
            DeliveryNoteController::class,
            'store',
        ])->name('delivery-notes.store');

        Route::get('/delivery-notes/{deliveryNote}', [
            DeliveryNoteController::class,
            'show',
        ])
            ->scopeBindings()
            ->name('delivery-notes.show');

        /*
        |--------------------------------------------------------------------------
        | ADMIN
        |--------------------------------------------------------------------------
        */

        Route::middleware('admin')->group(function () {

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
                    'pdf',
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
    Route::get('/files/reports/{report}/pdf', ReportPdfController::class)->name('reports.pdf');
});

Route::get('/files/exports/{companyExport}', CompanyExportDownloadController::class)
    ->middleware(['auth', 'subscription', 'throttle:20,1'])
    ->whereNumber('companyExport')
    ->name('company-exports.download');

Route::get('/files/elevator-documents/{elevatorDocument}', ElevatorDocumentController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('elevatorDocument')
    ->name('elevator-documents.show');

Route::get('/whatsapp/callback', [
    WhatsAppController::class,
    'callback',
])->middleware('auth')->name('whatsapp.callback');

/*
|--------------------------------------------------------------------------
| PORTAL DEL CLIENTE (consorcios / administraciones)
|--------------------------------------------------------------------------
| Solo lectura de lo que la empresa compartió, de los edificios autorizados
| (ver PortalAccess). Login con la pantalla común.
*/

Route::prefix('portal')->name('portal.')->middleware(['auth', 'portal'])->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('/edificios/{building}', [PortalController::class, 'building'])->whereNumber('building')->name('building');
    Route::get('/remitos/{deliveryNote}', [PortalController::class, 'deliveryNote'])->name('delivery-note');
    Route::get('/reportes/{report}', [PortalController::class, 'report'])->whereNumber('report')->name('report');
    Route::get('/reportes/{report}/fotos/{photo}', [PortalController::class, 'reportPhoto'])->whereNumber(['report', 'photo'])->name('report-photo');
    Route::get('/presupuestos/{quote}', [PortalController::class, 'quote'])->whereNumber('quote')->name('quote');
    Route::get('/documentos/{elevatorDocument}', [PortalController::class, 'document'])->whereNumber('elevatorDocument')->name('document');
});

/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

Route::get('/{company:slug}/quote/{token}', function (Company $company, $token) {

    // Link público (lo abre el cliente): empresa + token. Sin el scope de la
    // sesión: si en el navegador hay otra cuenta logueada, igual abre.
    $quote = Quote::withoutGlobalScopes()
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
