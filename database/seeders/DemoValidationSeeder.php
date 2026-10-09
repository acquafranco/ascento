<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Report;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Reports\ReportPhotoService;
use App\Services\Stock\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de validación realistas y reproducibles: dos empresas, técnicos,
 * clientes, edificios, ascensores, contratos con distintas frecuencias,
 * mantenimientos (hechos, no realizados y pendientes), inspecciones, órdenes
 * de trabajo con materiales, reportes con fotos, remitos, presupuestos,
 * stock, cobranzas, documentos privados y compartidos, y usuarios del portal.
 *
 *   php artisan db:seed --class=DemoValidationSeeder
 *
 * Seguridad:
 * - NUNCA corre en producción.
 * - No destructivo: si las empresas demo ya existen, no toca nada (no
 *   actualiza, no borra). Para regenerar, usar una base local nueva.
 * - Todas las cuentas usan el dominio reservado .test y la contraseña
 *   DEMO_PASSWORD; nada se envía (sin mails ni WhatsApp).
 */
class DemoValidationSeeder extends Seeder
{
    public const PASSWORD = 'demo-ascento';

    public const SLUGS = ['demo-norte', 'demo-sur'];

    private string $tmp;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoValidationSeeder no corre en producción.');

            return;
        }

        if (Company::withoutGlobalScopes()->whereIn('slug', self::SLUGS)->exists()) {
            $this->command?->warn('Las empresas demo ya existen: no se modificó nada. Usá una base local nueva para regenerarlas.');

            return;
        }

        $this->tmp = sys_get_temp_dir().'/ascento-demo-'.uniqid();
        mkdir($this->tmp);

        try {
            $this->north();
            $this->south();
        } finally {
            array_map('unlink', glob($this->tmp.'/*') ?: []);
            @rmdir($this->tmp);
            Auth::logout();
        }

        $this->command?->table(['Rol', 'Email', 'Ve'], [
            ['Admin Norte', 'admin@demo-norte.test', 'Panel completo de Demo Norte'],
            ['Técnico Norte', 'lucas@demo-norte.test', 'Torre Libertador y Edificio Juncal'],
            ['Técnica Norte', 'carla@demo-norte.test', 'Plaza Belgrano, Galería Cabildo e inspecciones'],
            ['Portal (administración)', 'admin@adm-rivadavia.test', 'Torre Libertador y Edificio Juncal'],
            ['Portal (encargado)', 'encargado@adm-rivadavia.test', 'Solo Edificio Juncal'],
            ['Portal (consorcio)', 'consejo@plaza-belgrano.test', 'Plaza Belgrano'],
            ['Admin Sur', 'admin@demo-sur.test', 'Panel completo de Demo Sur'],
            ['Portal Sur', 'portal@consorcio-sur.test', 'Edificio Quilmes Centro'],
        ]);
        $this->command?->info('Contraseña de todas las cuentas: '.self::PASSWORD);
    }

    private function north(): void
    {
        $company = $this->company('Ascensores Demo Norte', 'demo-norte');
        $admin = $this->staff($company, 'Marina Gómez (admin)', 'admin@demo-norte.test', 'admin');
        $lucas = $this->staff($company, 'Lucas Fernández', 'lucas@demo-norte.test', 'technician');
        $carla = $this->staff($company, 'Carla Ruiz', 'carla@demo-norte.test', 'technician');
        Auth::setUser($admin);

        // Clientes: una administración con dos edificios, un consorcio y una empresa.
        $adm = Client::create(['name' => 'Administración Rivadavia', 'type' => 'empresa', 'contact_person' => 'Sofía Rivadavia', 'phone' => '11 4000-1000', 'email' => 'admin@adm-rivadavia.test', 'is_active' => true]);
        $plaza = Client::create(['name' => 'Consorcio Plaza Belgrano', 'type' => 'consorcio', 'contact_person' => 'Consejo de administración', 'is_active' => true]);
        $galeria = Client::create(['name' => 'Galería Cabildo SRL', 'type' => 'empresa', 'is_active' => true, 'notes' => '=HYPERLINK("http://ejemplo.test","texto con forma de fórmula")']);

        $torre = $this->building($adm, 'Torre Libertador', 'Av. del Libertador 5200', 'Núñez', 3, 1);
        $juncal = $this->building($adm, 'Edificio Juncal', 'Juncal 2450', 'Recoleta', 2, 0);
        $belgrano = $this->building($plaza, 'Plaza Belgrano', 'Juramento 1800', 'Belgrano', 2, 0);
        $cabildo = $this->building($galeria, 'Galería Cabildo', 'Av. Cabildo 2100', 'Belgrano', 1, 1);

        // Contratos con distintas frecuencias de cobro (la visita sigue siendo
        // mensual). No se cobra retroactivo: cada inicio cae en un período
        // que vence este mes, así cada contrato genera su primera cobranza.
        $contracts = [
            $this->contract($torre, 'Abono mensual Torre Libertador', 180000, 'monthly', now()->subMonths(5)->startOfMonth()),
            $this->contract($juncal, 'Abono trimestral Edificio Juncal', 330000, 'quarterly', now()->subMonths(3)->startOfMonth()),
            $this->contract($belgrano, 'Abono semestral Plaza Belgrano', 600000, 'semiannual', now()->subMonths(6)->startOfMonth()),
            $this->contract($cabildo, 'Abono anual Galería Cabildo', 1300000, 'annual', now()->subMonths(12)->startOfMonth()),
        ];
        foreach ($contracts as $contract) {
            app(ServiceBillingService::class)->generate($contract);
        }

        // Asignaciones: cada técnico con sus edificios; Carla además inspecciona.
        $torre->users()->attach($lucas->id, ['type' => 'maintenance']);
        $juncal->users()->attach($lucas->id, ['type' => 'maintenance']);
        $belgrano->users()->attach($carla->id, ['type' => 'maintenance']);
        $cabildo->users()->attach($carla->id, ['type' => 'maintenance']);
        $torre->users()->attach($carla->id, ['type' => 'inspection']);
        $juncal->users()->attach($carla->id, ['type' => 'inspection']);

        // Mes anterior: todo hecho. Mes actual: hecho, no realizado y pendientes.
        $prev = now()->subMonthNoOverflow();
        foreach ([[$torre, $lucas], [$juncal, $lucas], [$belgrano, $carla], [$cabildo, $carla]] as [$b, $t]) {
            $this->remito($b, $t, 'maintenance', $prev, true, 'Mantenimiento preventivo mensual completo.');
        }
        $this->remito($torre, $carla, 'inspection', $prev, true, 'Inspección de seguridad sin observaciones.');
        $this->remito($torre, $lucas, 'maintenance', now(), true, 'Lubricación de guías, ajuste de puertas y prueba de frenos.');
        $this->remito($juncal, $lucas, 'maintenance', now(), false, 'No se pudo realizar: sala de máquinas cerrada, sin llave del encargado.');
        // Plaza Belgrano y Galería Cabildo quedan pendientes este mes (no vencidos).

        // Stock y una orden de trabajo completada con materiales.
        $stock = app(StockService::class);
        $contactor = StockItem::create(['name' => 'Contactor 220V', 'code' => 'CT-220', 'unit' => 'unidad', 'cost' => 45000, 'min_stock' => 2, 'is_active' => true]);
        $aceite = StockItem::create(['name' => 'Aceite hidráulico', 'code' => 'AC-HID', 'unit' => 'litro', 'cost' => 9000, 'min_stock' => 10, 'is_active' => true]);
        $boton = StockItem::create(['name' => 'Pulsador de cabina', 'code' => 'PB-01', 'unit' => 'unidad', 'cost' => 12000, 'min_stock' => 4, 'is_active' => true]);
        $stock->receive($contactor, 6, $admin, 'Compra inicial');
        $stock->receive($aceite, 40, $admin, 'Compra inicial');
        $stock->receive($boton, 3, $admin, 'Compra inicial'); // queda bajo el mínimo

        $done = WorkOrder::create(['building_id' => $belgrano->id, 'type' => 'claim', 'status' => 'in_progress', 'priority' => 'high', 'unit' => 'Ascensor 2', 'component' => 'doors', 'started_at' => now()->subDays(3), 'notes' => 'Puerta de cabina no cierra en PB.']);
        $done->users()->attach($carla->id);
        Auth::setUser($carla);
        $material = new WorkOrderMaterial(['work_order_id' => $done->id, 'stock_item_id' => $contactor->id, 'quantity' => 1]);
        $material->declared_by = $carla->id;
        $material->save();
        $note = $this->workOrderRemito($done, $carla, 'Se reemplazó el contactor de puertas. Funciona correctamente.');
        Auth::setUser($admin);
        $note->shareWithClient(true);

        $pending = WorkOrder::create(['building_id' => $juncal->id, 'type' => 'repair', 'status' => 'pending', 'priority' => 'medium', 'unit' => 'Ascensor 1', 'component' => 'buttons', 'notes' => 'Reponer pulsador de planta 4.']);
        $pending->users()->attach($lucas->id);

        // Reportes con fotos: uno compartido con el cliente, otro interno.
        $shared = Report::create(['building_id' => $torre->id, 'user_id' => $lucas->id, 'elevator_number' => 'Ascensor 1', 'component' => 'cabin', 'description' => 'Iluminación de cabina con tubo quemado.', 'observations' => 'Se recomienda pasar a LED.', 'priority' => 'media', 'status' => 'pendiente']);
        app(ReportPhotoService::class)->add($shared, [$this->photo('cabina')]);
        $shared->shareWithClient(true);
        $internal = Report::create(['building_id' => $juncal->id, 'user_id' => $lucas->id, 'elevator_number' => 'Ascensor 2', 'component' => 'motor', 'description' => 'Ruido en máquina. Revisar rodamientos (nota interna).', 'priority' => 'alta', 'status' => 'pendiente']);
        app(ReportPhotoService::class)->add($internal, [$this->photo('maquina')]);

        // Presupuestos: uno enviado y compartido, otro borrador privado.
        $q1 = $this->quote($torre, $admin, 'Modernización de iluminación de cabinas', Quote::SENT, [['Kit LED de cabina', 3, 85000], ['Mano de obra', 1, 120000]]);
        $q1->shareWithClient(true);
        $this->quote($juncal, $admin, 'Cambio de rodamientos de máquina (borrador)', Quote::DRAFT, [['Rodamientos', 2, 160000]]);

        // Cobranza: se registra un pago del contrato mensual.
        $receivable = Receivable::where('client_id', $adm->id)->orderBy('due_date')->first();
        if ($receivable) {
            app(ReceivableService::class)->registerPayment($receivable, (float) $receivable->amount, now()->startOfMonth()->addDays(4), 'transfer', 'Transferencia', $admin);
        }

        // Legajo: habilitación compartida, plano privado.
        $docs = app(ElevatorDocumentService::class);
        $elevator = Elevator::where('building_id', $torre->id)->orderBy('id')->first();
        $docs->store($elevator, $this->pdf('habilitacion'), 'certificate', 'Habilitación municipal '.now()->year, now()->addMonths(8)->toDateString(), $admin)->shareWithClient(true);
        $docs->store($elevator, $this->pdf('plano'), 'plan', 'Plano de sala de máquinas (interno)', null, $admin);

        // Portal: la administración ve sus dos edificios; el encargado solo Juncal.
        $this->portalUser($company, $adm, 'Sofía Rivadavia', 'admin@adm-rivadavia.test', [$torre, $juncal]);
        $this->portalUser($company, $adm, 'Encargado Juncal', 'encargado@adm-rivadavia.test', [$juncal]);
        $this->portalUser($company, $plaza, 'Consejo Plaza Belgrano', 'consejo@plaza-belgrano.test', [$belgrano]);

    }

    private function south(): void
    {
        $company = $this->company('Elevadores Demo Sur', 'demo-sur');
        $admin = $this->staff($company, 'Diego Paz (admin)', 'admin@demo-sur.test', 'admin');
        $tech = $this->staff($company, 'Nora Sosa', 'nora@demo-sur.test', 'technician');
        $this->staff($company, 'Pablo Díaz', 'pablo@demo-sur.test', 'technician');
        Auth::setUser($admin);

        $client = Client::create(['name' => 'Consorcio Quilmes Centro (dato de Demo Sur)', 'type' => 'consorcio', 'is_active' => true]);
        $building = $this->building($client, 'Edificio Quilmes Centro', 'Rivadavia 300', 'Quilmes', 2, 0);
        app(ServiceBillingService::class)->generate($this->contract($building, 'Abono bimestral Quilmes', 250000, 'bimonthly', now()->subMonths(2)->startOfMonth()));
        $building->users()->attach($tech->id, ['type' => 'maintenance']);
        $this->remito($building, $tech, 'maintenance', now()->subMonthNoOverflow(), true, 'Mantenimiento mensual (Demo Sur).');

        $report = Report::create(['building_id' => $building->id, 'user_id' => $tech->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Reporte privado de Demo Sur.', 'priority' => 'baja', 'status' => 'pendiente']);
        $report->shareWithClient(true);

        $this->portalUser($company, $client, 'Portal Quilmes', 'portal@consorcio-sur.test', [$building]);
    }

    // ---------------------------------------------------------------------

    private function company(string $name, string $slug): Company
    {
        // Sin usuario autenticado: BelongsToCompany fuerza la empresa del
        // actor, y las cuentas de la segunda empresa quedarían en la primera.
        Auth::logout();

        return Company::create(['name' => $name, 'slug' => $slug, 'is_active' => true, 'trial_ends_at' => now()->addDays(30)]);
    }

    private function staff(Company $company, string $name, string $email, string $role): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['role' => $role, 'company_id' => $company->id, 'email_verified_at' => now(), 'onboarding_skipped_at' => $role === 'admin' ? now() : null])->save();

        return $user;
    }

    private function portalUser(Company $company, Client $client, string $name, string $email, array $buildings): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make(self::PASSWORD)]);
        // Cuenta demo ya activada (en producción se crea por invitación).
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $company->id, 'client_id' => $client->id, 'email_verified_at' => now(), 'portal_invited_at' => now(), 'portal_activated_at' => now()])->save();
        $user->portalBuildings()->sync(collect($buildings)->pluck('id'));

        return $user;
    }

    private function building(Client $client, string $name, string $address, string $locality, int $elevators, int $freight): Building
    {
        return Building::create(['client_id' => $client->id, 'name' => $name, 'address' => $address, 'locality' => $locality, 'province' => 'Buenos Aires', 'elevator_count' => $elevators, 'freight_elevator_count' => $freight, 'is_active' => true]);
    }

    private function contract(Building $building, string $description, float $amount, string $frequency, $start): MaintenanceService
    {
        return MaintenanceService::create(['client_id' => $building->client_id, 'building_id' => $building->id, 'description' => $description, 'amount' => $amount, 'frequency' => $frequency, 'start_date' => $start->toDateString(), 'payment_due_day' => 10, 'status' => MaintenanceService::ACTIVE]);
    }

    /** Igual que DeliveryNoteController@store para mantenimiento / inspección. */
    private function remito(Building $building, User $tech, string $type, $date, bool $performed, string $description): DeliveryNote
    {
        $previous = Auth::user();
        Auth::setUser($tech);

        $visit = BuildingVisit::create([
            'building_id' => $building->id, 'user_id' => $tech->id, 'visit_type' => 'fixed', 'assignment_type' => $type,
            'month' => $date->month, 'year' => $date->year, 'status' => 'done', 'visited_at' => $date, 'source' => 'building',
        ]);
        $visit->participants()->syncWithPivotValues([$tech->id], ['role' => 'creator']);
        $note = DeliveryNote::create([
            'building_id' => $building->id, 'building_visit_id' => $visit->id, 'user_id' => $tech->id, 'assignment_type' => $type,
            'description' => $description, 'elevator_quantity' => $building->elevator_count, 'freight_elevator_quantity' => $building->freight_elevator_count,
            'performed' => $performed, 'month' => $date->month, 'year' => $date->year,
            'signature_name' => $tech->name, 'signature' => $this->signature(),
        ]);

        Auth::setUser($previous);

        return $note;
    }

    /** Igual que el cierre de una orden con remito (materiales → stock). */
    private function workOrderRemito(WorkOrder $order, User $tech, string $description): DeliveryNote
    {
        $note = DeliveryNote::create([
            'building_id' => $order->building_id, 'user_id' => $tech->id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order',
            'description' => $description, 'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'performed' => true,
            'month' => now()->month, 'year' => now()->year, 'signature_name' => $tech->name, 'signature' => $this->signature(),
        ]);
        $order->participants()->syncWithPivotValues([$tech->id], ['role' => 'creator']);
        $order->update(['status' => 'completed', 'finished_at' => now()]);
        $visit = BuildingVisit::create([
            'building_id' => $order->building_id, 'user_id' => $tech->id, 'source' => 'work_order', 'visit_type' => 'work_order',
            'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'month' => now()->month, 'year' => now()->year,
            'status' => 'done', 'visited_at' => now(), 'started_at' => $order->started_at, 'finished_at' => now(),
            'work_type' => $order->type, 'unit' => $order->unit,
        ]);
        $visit->participants()->syncWithPivotValues([$tech->id], ['role' => 'creator']);

        return $note;
    }

    private function quote(Building $building, User $admin, string $title, string $status, array $items): Quote
    {
        $quote = Quote::create(['building_id' => $building->id, 'client_id' => $building->client_id, 'created_by' => $admin->id, 'title' => $title, 'amount' => 0, 'status' => $status, 'issued_at' => now()->subDays(5)->toDateString(), 'valid_until' => now()->addDays(25)->toDateString(), 'conditions' => '50% de anticipo, saldo contra entrega.']);
        foreach ($items as $i => [$concept, $quantity, $price]) {
            $quote->items()->create(['position' => $i + 1, 'concept' => $concept, 'quantity' => $quantity, 'unit_price' => $price]);
        }

        return $quote->fresh();
    }

    private function photo(string $name): UploadedFile
    {
        $path = "{$this->tmp}/{$name}.jpg";
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 160));
        imagestring($image, 5, 240, 230, strtoupper($name), imagecolorallocate($image, 255, 255, 255));
        imagejpeg($image, $path, 80);
        imagedestroy($image);

        return new UploadedFile($path, "{$name}.jpg", 'image/jpeg', null, true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = "{$this->tmp}/{$name}.pdf";
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, "{$name}.pdf", 'application/pdf', null, true);
    }

    private function signature(): string
    {
        return 'data:image/png;base64,'.str_repeat('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ', 3);
    }
}
