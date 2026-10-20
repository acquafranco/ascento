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
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InspectionReportController;
use App\Http\Controllers\NotificationInboxController;
use App\Http\Controllers\OnCallController;
use App\Http\Controllers\ParachuteController;
use App\Http\Controllers\ParachuteTestFileController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalInvitationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicEmergencyController;
use App\Http\Controllers\PublicQrController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QuoteDocumentController;
use App\Http\Controllers\RepairComponentFileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ReportPhotoController;
use App\Http\Controllers\ReportVideoController;
use App\Http\Controllers\TechnicianCountersController;
use App\Http\Controllers\TechnicianPhotoController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\VisitPhotoController;
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

// Rutas anteriores a la sección «Servicios» unificada: los enlaces guardados
// siguen funcionando (redirigen a la vista nueva, con la misma subruta y filtros).
foreach (['admin/maintenance-services' => 'admin/servicios/contratados', 'admin/catalogo-servicios' => 'admin/servicios/ofrecidos'] as $old => $new) {
    Route::get($old.'/{path?}', fn (Request $request, ?string $path = null) => redirect()->to(
        url($new.($path ? '/'.$path : '')).($request->getQueryString() ? '?'.$request->getQueryString() : '')
    ))->where('path', '.*');
}

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

)->middleware('throttle:60,1,delivery-notes.public')->name('delivery-notes.public');

// QR público de un edificio (Etapa 7): solo lectura, sin cuenta, token aleatorio.
Route::get('/qr/{token}', [PublicQrController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->middleware('throttle:60,1,public-qr.show')->name('public-qr.show');

// Pedido de ayuda por atrapamiento desde el QR (sin cuenta). Sin CSRF de sesión
// (funciona con cookies bloqueadas): lo reemplaza el comprobante firmado de la
// página, que además es la clave de idempotencia. Límites por IP (además de los
// del servicio por edificio y por empresa).
Route::post('/qr/{token}/ayuda', [PublicEmergencyController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])
    ->middleware(['throttle:10,1,public-qr.help', 'throttle:40,60,public-qr.help-hour'])
    ->name('public-qr.help');

Route::get('/{company:slug}/public/delivery-notes/{token}/pdf', [DeliveryNoteController::class, 'publicPdf'])
    ->middleware('throttle:30,1,delivery-notes.public.pdf')->name('delivery-notes.public.pdf');

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
            ->middleware('throttle:60,1,technician.counters')->name('technician.counters');

        /*
        |--------------------------------------------------------------------------
        | PROFILE
        |--------------------------------------------------------------------------
        */

        // Ayuda de la app del técnico (Etapa 9.3).
        Route::get('/ayuda', [HelpController::class, 'technician'])->name('help.technician');
        // Bienvenida de la app (una sola vez por técnico: se marca al tocar «Entendido»).
        Route::post('/ayuda/bienvenida', [HelpController::class, 'dismissTechnicianWelcome'])->middleware('throttle:20,1,help.welcome')->name('help.technician.welcome');

        // Ubicación del técnico (voluntaria, solo la suya; ver TechnicianLocationController).
        Route::prefix('ubicacion')->name('location.')->controller(\App\Http\Controllers\TechnicianLocationController::class)->group(function () {
            Route::post('/', 'store')->middleware('throttle:6,1,location.store')->name('store');
            Route::post('/activar', 'enable')->middleware('throttle:20,1,location.toggle')->name('enable');
            Route::post('/desactivar', 'disable')->middleware('throttle:20,1,location.toggle')->name('disable');
            Route::post('/estado', 'report')->middleware('throttle:20,1,location.report')->name('report');
        });

        Route::controller(ProfileController::class)->group(function () {

            Route::get('/profile', 'edit')
                ->name('profile.edit');

            Route::patch('/profile', 'update')
                ->name('profile.update');

            // Cambio de correo pendiente de confirmación (Etapa 11).
            Route::post('/profile/correo/cancelar', 'cancelEmailChange')
                ->name('profile.email.cancel');

            // Foto de perfil del técnico (Etapa 8.2).
            Route::post('/profile/foto', 'updatePhoto')
                ->middleware('throttle:20,1,profile.photo.update')->name('profile.photo.update');

            Route::delete('/profile/foto', 'destroyPhoto')
                ->name('profile.photo.destroy');

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

        // Fotos y video se procesan en el servidor: tope por usuario (Etapa 11, H4).
        Route::post('/reports', [
            ReportController::class,
            'store',
        ])->middleware('throttle:20,1,reports.store')->name('reports.store');

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
        | GUARDIAS (consulta del técnico: solo sus asignaciones)
        |--------------------------------------------------------------------------
        */

        Route::get('/guardias', [OnCallController::class, 'index'])->name('on-call.index');
        Route::get('/paracaidas', [ParachuteController::class, 'index'])->name('parachute.index');

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
            ->middleware('throttle:20,1,push-subscriptions.store')
            ->name('push-subscriptions.store');

        Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
            ->middleware('throttle:20,1,push-subscriptions.destroy')
            ->name('push-subscriptions.destroy');

        Route::post('/push-subscriptions/test', [PushSubscriptionController::class, 'test'])
            ->middleware('throttle:3,1,push-subscriptions.test')
            ->name('push-subscriptions.test');

        // Telegram: canal adicional de avisos.
        Route::get('/telegram/connect', [TelegramLinkController::class, 'connect'])
            ->middleware('throttle:10,1,telegram.connect')
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
        ])->middleware('throttle:20,1,delivery-notes.store')->name('delivery-notes.store');

        // Visitas: la pantalla de una visita (en curso o con remito) y sus fotos.
        Route::post('/visits/start', [VisitController::class, 'start'])
            ->middleware('throttle:30,1,visits.start')->name('visits.start');
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
            ->whereNumber('visit')->middleware('throttle:20,1,inspection-reports.save')->name('inspection-reports.save');
        Route::delete('/visits/{visit}/report/photos/{photo}', [InspectionReportController::class, 'destroyPhoto'])
            ->whereNumber(['visit', 'photo'])->middleware('throttle:30,1,inspection-reports.photos.destroy')->name('inspection-reports.photos.destroy');

        // Fotos de antes y después: se suben de a una (cada una queda guardada al instante).
        Route::post('/visit-photos', [VisitPhotoController::class, 'store'])
            ->middleware('throttle:90,1,visit-photos.store')->name('visit-photos.store');
        Route::patch('/visit-photos/section', [VisitPhotoController::class, 'section'])
            ->middleware('throttle:60,1,visit-photos.section')->name('visit-photos.section');
        Route::delete('/visit-photos/{visitPhoto}', [VisitPhotoController::class, 'destroy'])
            ->whereNumber('visitPhoto')->middleware('throttle:90,1,visit-photos.destroy')->name('visit-photos.destroy');

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
                ->middleware('throttle:30,1,delivery-notes.pdf')
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
    Route::get('/files/reports/{report}/video/status', [ReportVideoController::class, 'status'])->middleware('throttle:30,1,reports.video.status')->name('reports.video.status');
    Route::get('/files/quotes/{quote}/pdf', [QuoteDocumentController::class, 'adminPdf'])->whereNumber('quote')->name('quotes.pdf');
    Route::post('/files/reports/{report}/video', [ReportVideoController::class, 'store'])->middleware('throttle:10,1,reports.video.store')->name('reports.video.store');
    Route::delete('/files/reports/{report}/video', [ReportVideoController::class, 'destroy'])->name('reports.video.destroy');
});

// Exportación de datos de la empresa (ZIP privado): solo admins de la
// empresa; cada descarga queda registrada (ver CompanyExportDownloadController).
Route::get('/files/exports/{companyExport}', CompanyExportDownloadController::class)
    ->middleware(['auth', 'subscription', 'throttle:20,1,company-exports.download'])
    ->whereNumber('companyExport')
    ->name('company-exports.download');

// Backups globales: solo SuperAdmin (ver BackupDownloadController).
Route::get('/files/backups/{backup}', BackupDownloadController::class)
    ->middleware(['auth', 'throttle:10,1,backups.download'])->whereNumber('backup')->name('backups.download');

// Administrativo que no puede entrar al panel (Etapa 6): el motivo, sin 403 genérico.
// Confirmación del correo nuevo (Etapa 11): se abre desde el correo, con o sin sesión.
Route::get('/correo/confirmar/{user}/{token}', \App\Http\Controllers\Auth\ConfirmEmailChangeController::class)
    ->whereNumber('user')
    ->middleware('throttle:20,1,email-change.confirm')
    ->name('email-change.confirm');

// Segundo factor del SuperAdmin (Etapa 11). EnsureSuperAdminTwoFactor deja
// pasar solo estas rutas (y salir) mientras falte configurarlo o verificarlo.
Route::middleware('auth')->prefix('seguridad/segundo-factor')->name('two-factor.')
    ->controller(\App\Http\Controllers\Auth\TwoFactorController::class)->group(function () {
        Route::get('/configurar', 'setup')->name('setup');
        Route::post('/configurar', 'start')->middleware('throttle:10,1,two-factor.start')->name('start');
        Route::post('/confirmar', 'confirm')->middleware('throttle:10,1,two-factor.confirm')->name('confirm');
        Route::get('/verificar', 'challenge')->name('challenge');
        Route::post('/verificar', 'verify')->middleware('throttle:10,1,two-factor.verify')->name('verify');
        Route::get('/', 'manage')->name('manage');
        Route::post('/codigos', 'regenerate')->middleware('throttle:5,1,two-factor.regenerate')->name('regenerate');
        Route::post('/reconfigurar', 'reconfigure')->middleware('throttle:5,1,two-factor.reconfigure')->name('reconfigure');
        Route::post('/restablecer', 'resetOther')->middleware('throttle:5,1,two-factor.reset-other')->name('reset-other');
    });

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

// Documentos y fotos de pruebas de paracaídas (Etapa 7): privados.
Route::get('/files/parachute-tests/{parachuteTestFile}', ParachuteTestFileController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('parachuteTestFile')
    ->name('parachute-test-files.show');

// Documentos y fotos de componentes en reparación (Etapa 8.4): privados.
Route::get('/files/repairs/{repairComponentFile}', RepairComponentFileController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('repairComponentFile')
    ->name('repair-component-files.show');

// Foto de perfil de un técnico (Etapa 8.2): privada (ver TechnicianPhotoController).
Route::get('/files/technician-photos/{user}', TechnicianPhotoController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('user')
    ->name('technician-photos.show');

// Foto de un reclamo de cliente, para el panel (privada; ver ClientClaimPhotoController).
Route::get('/files/client-claims/{photo}', \App\Http\Controllers\ClientClaimPhotoController::class)
    ->middleware(['auth', 'subscription', 'throttle:120,1,client-claim-photos'])
    ->whereNumber('photo')
    ->name('client-claim-photos.show');

// Imagen del QR público de un edificio (Etapa 7), para el panel.
Route::get('/qr-codes/{building}/download/{format}', [PublicQrController::class, 'download'])
    ->middleware(['auth', 'subscription', 'throttle:30,1,public-qr.download'])
    ->whereNumber('building')->whereIn('format', ['svg', 'png'])
    ->name('public-qr.download');

// Hoja para imprimir y pegar en el ascensor (A4 o compacta), con las mismas reglas que la descarga.
Route::get('/qr-codes/{building}/hoja/{size}', [PublicQrController::class, 'sheet'])
    ->middleware(['auth', 'subscription', 'throttle:30,1,public-qr.sheet'])
    ->whereNumber('building')->whereIn('size', ['a4', 'compacta'])
    ->name('public-qr.sheet');

Route::get('/files/elevator-documents/{elevatorDocument}', ElevatorDocumentController::class)
    ->middleware(['auth', 'subscription'])
    ->whereNumber('elevatorDocument')
    ->name('elevator-documents.show');

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
    // Pedidos de ayuda desde el QR de SUS edificios autorizados (solo lectura).
    Route::get('/pedidos-de-ayuda', [PortalController::class, 'emergencies'])->name('emergencies');
    Route::get('/servicio/{type}', [PortalController::class, 'visits'])->whereIn('type', ['maintenance', 'inspection'])->name('visits');
    Route::post('/empresa', [PortalController::class, 'switchCompany'])->name('switch-company');
    Route::get('/edificios/{building}', [PortalController::class, 'building'])->whereNumber('building')->name('building');
    Route::get('/remitos/{deliveryNote}', [PortalController::class, 'deliveryNote'])->name('delivery-note');
    Route::get('/remitos/{deliveryNote}/pdf', [PortalController::class, 'deliveryNotePdf'])->middleware('throttle:30,1,portal.delivery-note-pdf')->name('delivery-note-pdf');
    Route::get('/reportes/{report}', [PortalController::class, 'report'])->whereNumber('report')->name('report');
    Route::get('/reportes/{report}/fotos/{photo}', [PortalController::class, 'reportPhoto'])->whereNumber(['report', 'photo'])->name('report-photo');
    Route::get('/reportes/{report}/video', [PortalController::class, 'reportVideo'])->whereNumber('report')->name('report-video');
    Route::get('/presupuestos/{quote}', [PortalController::class, 'quote'])->whereNumber('quote')->name('quote');
    Route::get('/presupuestos/{quote}/pdf', [QuoteDocumentController::class, 'portalPdf'])->whereNumber('quote')->middleware('throttle:30,1,portal.quote-pdf')->name('quote-pdf');
    Route::get('/documentos/{elevatorDocument}', [PortalController::class, 'document'])->whereNumber('elevatorDocument')->name('document');
    // Foto de un técnico responsable de sus edificios (Etapa 8.3).
    Route::get('/responsables/{user}/foto', [PortalController::class, 'responsiblePhoto'])->whereNumber('user')->middleware('throttle:120,1,portal.responsible-photo')->name('responsible-photo');
    // Ayuda del portal (Etapa 9.3).
    Route::get('/ayuda', [HelpController::class, 'portal'])->name('help');
    // Reclamos a la empresa de mantenimiento (solo los propios, de sus edificios autorizados).
    Route::prefix('reclamos')->name('claims.')->controller(\App\Http\Controllers\Portal\PortalClaimController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/nuevo', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:10,1,portal.claims.store')->name('store');
        Route::get('/{claim}', 'show')->whereNumber('claim')->name('show');
        Route::get('/{claim}/fotos/{photo}', 'photo')->whereNumber(['claim', 'photo'])->middleware('throttle:120,1,portal.claims.photo')->name('photo');
    });
    Route::post('/ayuda/bienvenida', [HelpController::class, 'dismissPortalWelcome'])->middleware('throttle:20,1,portal.help.welcome')->name('help.welcome');
    Route::get('/notificaciones', [NotificationInboxController::class, 'index'])->name('notifications');
    Route::get('/notificaciones/contador', [NotificationInboxController::class, 'count'])->middleware('throttle:60,1,portal.notifications.count')->name('notifications.count');
    Route::get('/notificaciones/{notification}', [NotificationInboxController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('/notificaciones/leidas', [NotificationInboxController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notificaciones/vistas', [NotificationInboxController::class, 'seen'])->middleware('throttle:30,1,portal.notifications.seen')->name('notifications.seen');
    // Push en el celular del cliente (la suscripción queda a nombre del usuario autenticado).
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1,portal.push.store')->name('push.store');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1,portal.push.destroy')->name('push.destroy');
    Route::post('/push-subscriptions/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:3,1,portal.push.test')->name('push.test');
});

// Bandeja de avisos de los técnicos (los admins usan la campanita del panel).
Route::middleware(['auth', 'subscription'])->prefix('notificaciones')->name('notifications.')->group(function () {
    Route::get('/', [NotificationInboxController::class, 'index'])->name('index');
    Route::get('/contador', [NotificationInboxController::class, 'count'])->middleware('throttle:60,1,notifications.count')->name('count');
    Route::get('/{notification}', [NotificationInboxController::class, 'open'])->whereUuid('notification')->name('open');
    Route::post('/leidas', [NotificationInboxController::class, 'readAll'])->name('read-all');
    Route::post('/vistas', [NotificationInboxController::class, 'seen'])->middleware('throttle:30,1,notifications.seen')->name('seen');
});

// Ingreso y activación del portal (sin sesión). La activación usa el token
// de la invitación: de un solo uso, vence a las 72 h.
Route::middleware('guest')->prefix('portal')->name('portal.')->group(function () {
    Route::get('/ingresar', fn (\Illuminate\Http\Request $request) => view('auth.login', ['portal' => true, 'challenge' => \App\Services\Auth\AccountAttackGuard::challengeForView($request)]))->name('login');
    Route::get('/activar/{token}', [PortalInvitationController::class, 'show'])->middleware('throttle:20,1,portal.invitation')->name('invitation');
    Route::post('/activar', [PortalInvitationController::class, 'store'])->middleware('throttle:6,1,portal.invitation.store')->name('invitation.store');
});

/*
|--------------------------------------------------------------------------
| PUBLIC QUOTES
|--------------------------------------------------------------------------
*/

// Enlace del cliente: FIRMADO y con vencimiento (sin firma o vencido → aviso).
// Sin el scope de la sesión: abre aunque haya otra cuenta logueada.
Route::get('/{company:slug}/quote/{token}', [QuoteDocumentController::class, 'public'])
    ->middleware('throttle:60,1,quotes.public')->name('quotes.public');
Route::get('/{company:slug}/quote/{token}/pdf', [QuoteDocumentController::class, 'publicPdf'])
    ->middleware('throttle:30,1,quotes.public.pdf')->name('quotes.public.pdf');
/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
