<?php

use App\Enums\PlanLimit;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\BuildingCheckController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyExportDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\ElevatorDocumentController;
use App\Http\Controllers\InspectionReportController;
use App\Http\Controllers\NotificationInboxController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalInvitationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QuoteDocumentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ReportPhotoController;
use App\Http\Controllers\ReportVideoController;
use App\Http\Controllers\TechnicianCountersController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\VisitPhotoController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WorkOrderController;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\HomeRedirect;
use Illuminate\Http\Request;
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
Route::get('/legal/cookies', fn () => view('legal.cookies'))->name('legal.cookies');

/*
|--------------------------------------------------------------------------
| PUBLIC DELIVERY NOTE
|--------------------------------------------------------------------------
*/

Route::get(

    '/{company:slug}/public/delivery-notes/{token}',

    [DeliveryNoteController::class, 'showPublic']

)->middleware('throttle:60,1')->name('delivery-notes.public');

Route::get('/{company:slug}/public/delivery-notes/{token}/pdf', [DeliveryNoteController::class, 'publicPdf'])
    ->middleware('throttle:30,1')->name('delivery-notes.public.pdf');

/*
|--------------------------------------------------------------------------
| COMPANY APPLICATION
|--------------------------------------------------------------------------
*/

Route::prefix('{company:slug}')
    // Nunca captura rutas del sistema (/portal, /notificaciones, ...).
    ->where(['company' => '(?!(?:'.implode('|', array_map('preg_quote', Company::RESERVED_SLUGS)).')(?![A-Za-z0-9_.-]))[A-Za-z0-9_-]+'])
    ->middleware([
        'auth',
        'company',
        'company.defaults',
        // Sin suscripción/trial vigente no se opera (técnicos ni admins).
        'subscription',
        // Un administrativo no usa la app del técnico (Etapa 6).
        'staff.panel',
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

        // Contadores del técnico (para ponerse al día en tiempo real o al reconectar).
        Route::get('/dashboard/contadores', TechnicianCountersController::class)
            ->middleware('throttle:60,1')->name('technician.counters');

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

        // Visitas: la pantalla de una visita (en curso o con remito) y sus fotos.
        Route::post('/visits/start', [VisitController::class, 'start'])
            ->middleware('throttle:30,1')->name('visits.start');
        Route::get('/visits/{visit}', [VisitController::class, 'show'])
            ->whereNumber('visit')->name('visits.show');
        Route::delete('/visits/{visit}', [VisitController::class, 'discard'])
            ->whereNumber('visit')->name('visits.discard');
        Route::get('/work-orders/{workOrder}/photos', [VisitController::class, 'workOrder'])
            ->whereNumber('workOrder')->name('work-orders.photos');

        // Reporte de la inspección (observaciones y fotos del inspector).
        Route::get('/visits/{visit}/report', [InspectionReportController::class, 'show'])
            ->whereNumber('visit')->name('inspection-reports.show');
        Route::post('/visits/{visit}/report', [InspectionReportController::class, 'save'])
            ->whereNumber('visit')->middleware('throttle:20,1')->name('inspection-reports.save');
        Route::delete('/visits/{visit}/report/photos/{photo}', [InspectionReportController::class, 'destroyPhoto'])
            ->whereNumber(['visit', 'photo'])->middleware('throttle:30,1')->name('inspection-reports.photos.destroy');

        // Fotos de antes y después: se suben de a una (cada una queda guardada al instante).
        Route::post('/visit-photos', [VisitPhotoController::class, 'store'])
            ->middleware('throttle:90,1')->name('visit-photos.store');
        Route::patch('/visit-photos/section', [VisitPhotoController::class, 'section'])
            ->middleware('throttle:60,1')->name('visit-photos.section');
        Route::delete('/visit-photos/{visitPhoto}', [VisitPhotoController::class, 'destroy'])
            ->whereNumber('visitPhoto')->middleware('throttle:90,1')->name('visit-photos.destroy');

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

            // PDF del remito (solo admins, como antes). Los técnicos comparten el
            // PDF del enlace público del remito.
            Route::get('/delivery-notes/{deliveryNote}/pdf', [DeliveryNoteController::class, 'pdf'])
                ->middleware('throttle:30,1')
                ->name('delivery-notes.pdf');

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
    Route::get('/files/reports/{report}/video', [ReportVideoController::class, 'show'])->name('reports.video');
    Route::get('/files/reports/{report}/video/status', [ReportVideoController::class, 'status'])->middleware('throttle:30,1')->name('reports.video.status');
    Route::get('/files/quotes/{quote}/pdf', [QuoteDocumentController::class, 'adminPdf'])->whereNumber('quote')->name('quotes.pdf');
    Route::post('/files/reports/{report}/video', [ReportVideoController::class, 'store'])->middleware('throttle:10,1')->name('reports.video.store');
    Route::delete('/files/reports/{report}/video', [ReportVideoController::class, 'destroy'])->name('reports.video.destroy');
});

// Exportación de datos de la empresa (ZIP privado): solo admins de la
// empresa; cada descarga queda registrada (ver CompanyExportDownloadController).
Route::get('/files/exports/{companyExport}', CompanyExportDownloadController::class)
    ->middleware(['auth', 'subscription', 'throttle:20,1'])
    ->whereNumber('companyExport')
    ->name('company-exports.download');

// Backups globales: solo SuperAdmin (ver BackupDownloadController).
Route::get('/files/backups/{backup}', BackupDownloadController::class)
    ->middleware(['auth', 'throttle:10,1'])->whereNumber('backup')->name('backups.download');

// Administrativo que no puede entrar al panel (Etapa 6): el motivo, sin 403 genérico.
Route::get('/acceso-administrativo', function (Request $request) {
    $user = $request->user();

    if (! Access::isStaff($user) || Access::isActiveStaff($user)) {
        return HomeRedirect::to();
    }

    $company = Company::find($user->company_id);
    $reason = Access::staffBlockReason($user);

    return response()->view('staff-blocked', [
        'company' => $company,
        'message' => Access::blockMessage($reason, $company?->plan()->limit(PlanLimit::AdminUsers)),
    ], 403);
})->middleware('auth')->name('staff.blocked');

// Fotos de antes y después de visitas: privadas (ver VisitPhotoController).
Route::get('/files/visit-photos/{visitPhoto}', [VisitPhotoController::class, 'show'])
    ->middleware(['auth', 'subscription'])
    ->whereNumber('visitPhoto')
    ->name('visit-photos.show');

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
    Route::get('/documentos', [PortalController::class, 'documents'])->name('documents');
    Route::get('/servicio/{type}', [PortalController::class, 'visits'])->whereIn('type', ['maintenance', 'inspection'])->name('visits');
    Route::post('/empresa', [PortalController::class, 'switchCompany'])->name('switch-company');
    Route::get('/edificios/{building}', [PortalController::class, 'building'])->whereNumber('building')->name('building');
    Route::get('/remitos/{deliveryNote}', [PortalController::class, 'deliveryNote'])->name('delivery-note');
    Route::get('/remitos/{deliveryNote}/pdf', [PortalController::class, 'deliveryNotePdf'])->middleware('throttle:30,1')->name('delivery-note-pdf');
    Route::get('/reportes/{report}', [PortalController::class, 'report'])->whereNumber('report')->name('report');
    Route::get('/reportes/{report}/fotos/{photo}', [PortalController::class, 'reportPhoto'])->whereNumber(['report', 'photo'])->name('report-photo');
    Route::get('/reportes/{report}/video', [PortalController::class, 'reportVideo'])->whereNumber('report')->name('report-video');
    Route::get('/presupuestos/{quote}', [PortalController::class, 'quote'])->whereNumber('quote')->name('quote');
    Route::get('/presupuestos/{quote}/pdf', [QuoteDocumentController::class, 'portalPdf'])->whereNumber('quote')->middleware('throttle:30,1')->name('quote-pdf');
    Route::get('/documentos/{elevatorDocument}', [PortalController::class, 'document'])->whereNumber('elevatorDocument')->name('document');
    Route::get('/notificaciones', [NotificationInboxController::class, 'index'])->name('notifications');
    Route::get('/notificaciones/contador', [NotificationInboxController::class, 'count'])->middleware('throttle:60,1')->name('notifications.count');
    Route::get('/notificaciones/{notification}', [NotificationInboxController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('/notificaciones/leidas', [NotificationInboxController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notificaciones/vistas', [NotificationInboxController::class, 'seen'])->middleware('throttle:30,1')->name('notifications.seen');
    // Push en el celular del cliente (la suscripción queda a nombre del usuario autenticado).
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.store');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('push.destroy');
    Route::post('/push-subscriptions/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:3,1')->name('push.test');
});

// Bandeja de avisos de los técnicos (los admins usan la campanita del panel).
Route::middleware(['auth', 'subscription'])->prefix('notificaciones')->name('notifications.')->group(function () {
    Route::get('/', [NotificationInboxController::class, 'index'])->name('index');
    Route::get('/contador', [NotificationInboxController::class, 'count'])->middleware('throttle:60,1')->name('count');
    Route::get('/{notification}', [NotificationInboxController::class, 'open'])->whereUuid('notification')->name('open');
    Route::post('/leidas', [NotificationInboxController::class, 'readAll'])->name('read-all');
    Route::post('/vistas', [NotificationInboxController::class, 'seen'])->middleware('throttle:30,1')->name('seen');
});

// Ingreso y activación del portal (sin sesión). La activación usa el token
// de la invitación: de un solo uso, vence a las 72 h.
Route::middleware('guest')->prefix('portal')->name('portal.')->group(function () {
    Route::get('/ingresar', fn () => view('auth.login', ['portal' => true]))->name('login');
    Route::get('/activar/{token}', [PortalInvitationController::class, 'show'])->middleware('throttle:20,1')->name('invitation');
    Route::post('/activar', [PortalInvitationController::class, 'store'])->middleware('throttle:6,1')->name('invitation.store');
});

/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

// Enlace del cliente: FIRMADO y con vencimiento (sin firma o vencido → aviso).
// Sin el scope de la sesión: abre aunque haya otra cuenta logueada.
Route::get('/{company:slug}/quote/{token}', [QuoteDocumentController::class, 'public'])
    ->middleware('throttle:60,1')->name('quotes.public');
Route::get('/{company:slug}/quote/{token}/pdf', [QuoteDocumentController::class, 'publicPdf'])
    ->middleware('throttle:30,1')->name('quotes.public.pdf');
/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
