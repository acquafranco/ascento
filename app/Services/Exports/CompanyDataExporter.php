<?php

namespace App\Services\Exports;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\PortalMembership;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\ReportVideo;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Support\ElevatorComponents;
use App\Support\WorkOrderLabels;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Arma el ZIP de una exportación: un Excel con una hoja por tipo de dato,
 * los adjuntos (fotos de reportes y documentos de los ascensores) y un LEEME.
 *
 * Seguridad:
 * - Corre sin usuario (scheduler): los scopes globales NO aplican, así que
 *   TODA consulta filtra explícitamente por company_id.
 * - Nunca exporta contraseñas, hashes, tokens, sesiones, firmas en imagen,
 *   datos de WhatsApp/Telegram ni de Mercado Pago.
 * - Celdas con tipo explícito: un texto que empieza con "=" queda como texto
 *   (OpenSpout convertiría en fórmula un valor sin tipo).
 * - Adjuntos solo de las carpetas de la empresa y verificados con realpath.
 * - Lee de a bloques y escribe en streaming (no arma el archivo en memoria).
 */
class CompanyDataExporter
{
    private const CHUNK = 500;

    private int $companyId;

    private Writer $writer;

    private bool $firstSheet = true;

    private Style $header;

    private Style $date;

    private Style $day;

    /** @var array<string, int> hoja => filas */
    private array $categories = [];

    /** @var list<array{0: string, 1: string, 2: string}> archivo en el ZIP, origen, registro */
    private array $files = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array<int, string>> */
    private array $names = [];

    /** @return array{path: string, file_name: string, size: int, record_count: int, categories: array<string, int>, warnings: list<string>} */
    public function export(CompanyExport $export): array
    {
        $company = Company::findOrFail($export->company_id);
        $this->companyId = (int) $company->id;
        $this->header = (new Style)->setFontBold();
        $this->date = (new Style)->setFormat('dd/mm/yyyy hh:mm');
        $this->day = (new Style)->setFormat('dd/mm/yyyy');

        $directory = 'exports/'.$this->companyId;
        $stamp = now()->format('Ymd-His');
        $fileName = 'ascento-'.Str::slug($company->name).'-'.$stamp.'.zip';
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        $xlsx = $disk->path($directory.'/tmp-'.$export->id.'-'.Str::random(8).'.xlsx');
        $zipPath = $directory.'/'.$fileName;

        try {
            $this->loadNames();

            $this->writer = new Writer;
            $this->writer->openToFile($xlsx);
            $this->writeSheets($company);
            $this->writeFilesIndex();
            $this->writer->close();

            $zip = new ZipArchive;
            if ($zip->open($disk->path($zipPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo ZIP.');
            }
            $zip->addFile($xlsx, 'datos-'.Str::slug($company->name).'.xlsx');
            $zip->addFromString('LEEME.txt', $this->readme($company));
            foreach ($this->files as [$inZip, $absolute]) {
                $zip->addFile($absolute, $inZip);
            }
            if ($zip->close() !== true) {
                throw new RuntimeException('No se pudo cerrar el archivo ZIP.');
            }
        } catch (\Throwable $e) {
            $disk->delete($zipPath);

            throw $e;
        } finally {
            @unlink($xlsx);
        }

        return [
            'path' => $zipPath,
            'file_name' => $fileName,
            'size' => (int) $disk->size($zipPath),
            'record_count' => array_sum($this->categories),
            'categories' => $this->categories,
            'warnings' => $this->warnings,
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /** Consulta de un modelo SOLO de esta empresa (sin scopes globales). */
    private function scoped(string $model, bool $withTrashed = false): Builder
    {
        $query = $model::withoutGlobalScopes()->where((new $model)->getTable().'.company_id', $this->companyId);

        return $withTrashed && method_exists($model, 'bootSoftDeletes') ? $query->withTrashed() : $query;
    }

    /** Nombres para columnas descriptivas (edificio, cliente, técnico…). */
    private function loadNames(): void
    {
        $this->names = [
            'clients' => Client::withoutGlobalScopes()->withTrashed()->where('company_id', $this->companyId)->pluck('name', 'id')->all(),
            'buildings' => Building::withoutGlobalScopes()->withTrashed()->where('company_id', $this->companyId)->pluck('name', 'id')->all(),
            'users' => User::withTrashed()->where('company_id', $this->companyId)->pluck('name', 'id')->all(),
            'stock' => StockItem::withoutGlobalScopes()->withTrashed()->where('company_id', $this->companyId)->pluck('name', 'id')->all(),
            'elevators' => Elevator::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('label', 'id')->all(),
            // Para ubicar adjuntos sin una consulta por fila.
            'elevator_building' => Elevator::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('building_id', 'id')->all(),
            'report_building' => Report::withoutGlobalScopes()->withTrashed()->where('company_id', $this->companyId)->pluck('building_id', 'id')->all(),
        ];
    }

    private function name(string $map, mixed $id): ?string
    {
        return $id === null ? null : ($this->names[$map][$id] ?? null);
    }

    private function cell(mixed $value, bool $dateOnly = false): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null, null),
            $value instanceof DateTimeInterface => new DateTimeCell($value, $dateOnly ? $this->day : $this->date),
            is_bool($value) => new StringCell($value ? 'Sí' : 'No', null),
            is_int($value), is_float($value) => new NumericCell($value, null),
            // Siempre texto: nunca una fórmula, aunque empiece con =, +, - o @.
            default => new StringCell((string) $value, null),
        };
    }

    private function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    /**
     * Escribe una hoja. $rows recibe cada fila como array de valores; las
     * columnas que terminan en "*" se escriben como fecha sin hora.
     *
     * @param  list<string>  $headers
     */
    private function sheet(string $name, array $headers, Builder $query, callable $row, string $orderBy = 'id'): void
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            return; // sin hojas vacías
        }

        if ($this->firstSheet) {
            $this->firstSheet = false;
        } else {
            $this->writer->addNewSheetAndMakeItCurrent();
        }
        $this->writer->getCurrentSheet()->setName(mb_substr($name, 0, 31));

        $dateOnly = array_map(fn ($h) => str_ends_with($h, '*'), $headers);
        $this->writer->addRow(new Row(array_map(fn ($h) => new StringCell(rtrim($h, '*'), $this->header), $headers)));

        $query->chunkById(self::CHUNK, function ($records) use ($row, $dateOnly) {
            foreach ($records as $record) {
                $values = array_values($row($record));
                $this->writer->addRow(new Row(array_map(fn ($v, $i) => $this->cell($v, $dateOnly[$i] ?? false), $values, array_keys($values))));
            }
        }, $orderBy === 'id' ? $query->getModel()->getQualifiedKeyName() : $orderBy, 'id');

        $this->categories[$name] = $total;
    }

    /** Archivo del disco de la empresa → entrada del ZIP (validado). */
    private function attach(string $diskPath, string $prefix, string $inZip, string $record): bool
    {
        if ($diskPath === '' || str_contains($diskPath, '..') || ! str_starts_with($diskPath, $prefix.$this->companyId.'/')) {
            $this->warnings[] = "{$record}: ruta no válida, no se incluyó.";

            return false;
        }

        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);
            $root = realpath($disk->path(''));
            $absolute = realpath($disk->path($diskPath));

            if ($root && $absolute && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) && is_file($absolute) && ! is_link($disk->path($diskPath))) {
                $this->files[] = [$inZip, $absolute, $record];

                return true;
            }
        }

        $this->warnings[] = "{$record}: el archivo no está en el servidor, no se incluyó.";

        return false;
    }

    /* ------------------------------------------------------------- hojas */

    private function writeSheets(Company $company): void
    {
        // Empresa (campo / valor). Sin tokens ni datos de integraciones.
        $this->writer->getCurrentSheet()->setName('Empresa');
        $this->firstSheet = false;
        $this->writer->addRow(new Row([new StringCell('Dato', $this->header), new StringCell('Valor', $this->header)]));
        foreach ([
            'Nombre' => $company->name, 'Razón social' => $company->business_name, 'CUIT' => $company->cuit,
            'Condición frente al IVA' => $company->tax_condition, 'Actividad' => $company->activity,
            'Ingresos Brutos' => $company->gross_income_number, 'Inicio de actividades' => $company->activity_started_at,
            'Email' => $company->email, 'Teléfono' => $company->phone, 'Domicilio' => $company->address,
            'Localidad' => $company->city, 'Provincia' => $company->province, 'Código postal' => $company->postal_code,
            'Banco' => $company->bank_name, 'CBU / CVU' => $company->bank_cbu, 'Alias' => $company->bank_alias,
            'Plan' => $company->plan()->name, 'Alta en Ascento' => $company->created_at, 'Exportado el' => now(),
        ] as $label => $value) {
            $this->writer->addRow(new Row([new StringCell($label, null), $this->cell($value)]));
        }
        $this->categories['Empresa'] = 1;

        $this->sheet('Clientes', ['ID', 'Nombre', 'Tipo', 'Contacto', 'Teléfono', 'Email', 'Notas', 'Activo', 'Eliminado', 'Alta'],
            $this->scoped(Client::class, true), fn ($c) => [$c->id, $c->name, $c->type, $c->contact_person, $c->phone, $c->email, $c->notes, (bool) $c->is_active, $c->deleted_at !== null, $c->created_at]);

        $this->sheet('Edificios', ['ID', 'Cliente ID', 'Cliente', 'Nombre', 'Dirección', 'Localidad', 'Municipio', 'Provincia', 'Barrio', 'Contacto', 'Teléfono', 'Ascensores', 'Montacargas', 'Tracción', 'Hidráulicos', 'Latitud', 'Longitud', 'Notas', 'Activo', 'Eliminado', 'Alta'],
            $this->scoped(Building::class, true), fn ($b) => [$b->id, $b->client_id, $this->name('clients', $b->client_id), $b->name, $b->address, $b->locality, $b->municipality, $b->province, $b->neighborhood, $b->contact_person, $b->phone,
                (int) $b->elevator_count, (int) $b->freight_elevator_count, (int) $b->traction_elevator_count, (int) $b->hydraulic_elevator_count, $b->latitude !== null ? (float) $b->latitude : null, $b->longitude !== null ? (float) $b->longitude : null, $b->notes, (bool) $b->is_active, $b->deleted_at !== null, $b->created_at]);

        $this->sheet('Ascensores', ['ID', 'Edificio ID', 'Edificio', 'Equipo', 'Tipo', 'Activo', 'Fabricante', 'Modelo', 'N° de serie', 'Año', 'Capacidad (kg)', 'Personas', 'Velocidad (m/s)', 'Paradas', 'Instalación*', 'Instalador', 'Máquina', 'Controlador', 'Motor', 'Puertas', 'Operador de puertas', 'Otros componentes', 'Notas'],
            $this->scoped(Elevator::class), fn ($e) => [$e->id, $e->building_id, $this->name('buildings', $e->building_id), $e->label, $e->kind === 'freight' ? 'Montacargas' : 'Ascensor', (bool) $e->is_active,
                $e->manufacturer, $e->model, $e->serial_number, $e->year, $e->capacity_kg, $e->capacity_people, $e->speed_ms !== null ? (float) $e->speed_ms : null, $e->stops, $e->installed_at, $e->installer,
                Elevator::MACHINE_TYPES[$e->machine_type] ?? $e->machine_type, $e->controller, $e->motor, $e->doors, $e->door_operator, $e->components, $e->notes]);

        $documents = $this->scoped(ElevatorDocument::class);
        $this->sheet('Documentos de ascensores', ['ID', 'Ascensor ID', 'Edificio', 'Equipo', 'Tipo', 'Título', 'Archivo en el ZIP', 'Vence*', 'Compartido con el cliente', 'Subido'],
            $documents, function ($d) {
                $buildingId = $this->names['elevator_building'][$d->elevator_id] ?? null;
                $label = $this->name('elevators', $d->elevator_id);
                $inZip = 'adjuntos/documentos/'.Str::slug($this->name('buildings', $buildingId) ?? 'edificio').'/'.Str::slug($label ?? 'equipo').'/'.$d->id.'-'.Str::slug($d->title ?: 'documento').'.'.pathinfo((string) $d->path, PATHINFO_EXTENSION);
                $ok = $this->attach((string) $d->path, 'elevators/', $inZip, "Documento #{$d->id}");

                return [$d->id, $d->elevator_id, $this->name('buildings', $buildingId), $label, ElevatorDocument::TYPES[$d->type] ?? $d->type, $d->title, $ok ? $inZip : '(no incluido)', $d->expires_at, (bool) $d->shared_with_client, $d->created_at];
            });

        $this->sheet('Contratos', ['ID', 'Cliente ID', 'Cliente', 'Edificio ID', 'Edificio', 'Servicio', 'Importe por período', 'Frecuencia de cobro', 'Inicio*', 'Fin*', 'Estado', 'Día de vencimiento', 'Notas', 'Eliminado'],
            $this->scoped(MaintenanceService::class, true), fn ($s) => [$s->id, $s->client_id, $this->name('clients', $s->client_id), $s->building_id, $this->name('buildings', $s->building_id), $s->description, $this->money($s->amount),
                MaintenanceService::FREQUENCIES[$s->frequency][0] ?? $s->frequency, $s->start_date, $s->end_date, MaintenanceService::STATUSES[$s->status] ?? $s->status, $s->payment_due_day, $s->notes, $s->deleted_at !== null]);

        // Usuarios: personal de la empresa, solo datos operativos (nunca contraseña, tokens ni chats).
        $this->sheet('Usuarios', ['ID', 'Nombre', 'Email', 'Rol', 'Cliente (portal)', 'Teléfono', 'Estado', 'Alta'],
            User::withTrashed()->where('company_id', $this->companyId)->where('is_super_admin', false)->where('role', '!=', User::ROLE_CLIENT),
            fn ($u) => [$u->id, $u->name, $u->email, match ($u->role) {
                'admin' => 'Administrador', User::ROLE_CLIENT => 'Portal del cliente', default => 'Técnico'
            },
                $this->name('clients', $u->client_id), $u->phone, $u->deleted_at ? 'Desactivado' : 'Activo', $u->created_at]);

        // Accesos al portal que dio ESTA empresa (la persona puede tener otros
        // accesos en otras empresas: esos no se exportan).
        $this->sheet('Accesos al portal', ['ID', 'Persona', 'Email', 'Cliente ID', 'Cliente', 'Edificios autorizados', 'Invitación', 'Activado', 'Estado'],
            PortalMembership::where('company_id', $this->companyId)->with('user:id,name,email,deleted_at'),
            fn ($m) => [$m->id, $m->user?->name, $m->user?->email, $m->client_id, $this->name('clients', $m->client_id),
                collect($m->buildingIds())->map(fn ($id) => $this->name('buildings', $id))->implode(', '),
                $m->invited_at, $m->activated_at, $m->deactivated_at ? 'Desactivado' : 'Activo']);

        $this->sheet('Asignaciones', ['Edificio ID', 'Edificio', 'Técnico ID', 'Técnico', 'Trabajo', 'Desde'],
            Building::withoutGlobalScopes()->withTrashed()->where('buildings.company_id', $this->companyId)
                ->join('building_user', 'building_user.building_id', '=', 'buildings.id')
                ->select('building_user.id', 'buildings.id as building_id', 'building_user.user_id', 'building_user.type', 'building_user.created_at as assigned_at'),
            fn ($a) => [$a->building_id, $this->name('buildings', $a->building_id), $a->user_id, $this->name('users', $a->user_id), $a->type === 'inspection' ? 'Inspección' : 'Mantenimiento', $a->assigned_at ? Carbon::parse($a->assigned_at) : null],
            'building_user.id');

        foreach (['maintenance' => 'Mantenimientos', 'inspection' => 'Inspecciones'] as $type => $sheet) {
            $this->sheet($sheet, ['ID', 'Período', 'Fecha', 'Edificio ID', 'Edificio', 'Técnico', 'Remito', 'Realizado', 'Contrato ID'],
                $this->scoped(BuildingVisit::class)->where('visit_type', 'fixed')->where('assignment_type', $type)->with(['deliveryNote' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'building_visit_id', 'number', 'performed')]),
                fn ($v) => [$v->id, str_pad((string) $v->month, 2, '0', STR_PAD_LEFT).'/'.$v->year, $v->visited_at, $v->building_id, $this->name('buildings', $v->building_id), $this->name('users', $v->user_id),
                    $v->deliveryNote?->number, $v->deliveryNote ? (bool) $v->deliveryNote->performed : null, $v->maintenance_service_id]);
        }

        $this->sheet('Órdenes de trabajo', ['ID', 'Edificio ID', 'Edificio', 'Equipo', 'Tipo', 'Componente', 'Estado', 'Prioridad', 'Técnicos', 'Creada', 'Iniciada', 'Terminada', 'Notas', 'Remito', 'Eliminada'],
            $this->scoped(WorkOrder::class, true)->with(['users:id,name', 'deliveryNote' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'work_order_id', 'number')]),
            fn ($o) => [$o->id, $o->building_id, $this->name('buildings', $o->building_id), $o->unit, WorkOrderLabels::type((string) $o->type), $o->component ? ElevatorComponents::label($o->component) : null,
                WorkOrderLabels::status((string) $o->status), WorkOrderLabels::priority((string) $o->priority), $o->users->pluck('name')->implode(', '), $o->created_at, $o->started_at, $o->finished_at, $o->notes, $o->deliveryNote?->number, $o->deleted_at !== null]);

        $this->sheet('Materiales usados', ['Orden ID', 'Material', 'Cantidad', 'Costo unitario', 'Declarado por', 'Descontado del stock'],
            $this->scoped(WorkOrderMaterial::class), fn ($m) => [$m->work_order_id, $this->name('stock', $m->stock_item_id), (float) $m->quantity, $this->money($m->unit_cost), $this->name('users', $m->declared_by) ?? 'Oficina', $m->stock_movement_id !== null]);

        $photoIndex = [];
        $this->sheet('Reportes', ['ID', 'Fecha', 'Edificio ID', 'Edificio', 'Equipo', 'Técnico', 'Prioridad', 'Estado', 'Componente', 'Descripción', 'Observaciones', 'Fotos', 'Compartido con el cliente', 'Eliminado'],
            $this->scoped(Report::class, true)->withCount(['photos' => fn ($q) => $q->withoutGlobalScopes()]),
            fn ($r) => [$r->id, $r->created_at, $r->building_id, $this->name('buildings', $r->building_id), $r->elevator_number, $this->name('users', $r->user_id), $r->priority, $r->status,
                $r->component ? ElevatorComponents::label($r->component) : null, $r->description, $r->observations, (int) $r->photos_count, (bool) $r->shared_with_client, $r->deleted_at !== null]);

        // Fotos de reportes → adjuntos (índice en la hoja "Archivos").
        $this->scoped(ReportPhoto::class)->orderBy('report_id')->orderBy('position')->chunkById(self::CHUNK, function ($photos) {
            foreach ($photos as $photo) {
                $building = Str::slug($this->name('buildings', $this->names['report_building'][$photo->report_id] ?? null) ?? 'edificio');
                $this->attach((string) $photo->path, 'reports/', 'adjuntos/reportes/'.$building.'/reporte-'.$photo->report_id.'-foto-'.($photo->position + 1).'.jpg', "Reporte #{$photo->report_id} (foto ".($photo->position + 1).')');
            }
        });

        // Videos de reportes → adjuntos/videos (mismas validaciones de ruta).
        $this->scoped(ReportVideo::class)->orderBy('report_id')->chunkById(self::CHUNK, function ($videos) {
            foreach ($videos as $video) {
                $building = Str::slug($this->name('buildings', $this->names['report_building'][$video->report_id] ?? null) ?? 'edificio');
                $this->attach((string) $video->path, 'reports/', 'adjuntos/videos/'.$building.'/reporte-'.$video->report_id.'.'.(ReportVideo::MIMES[$video->mime] ?? 'mp4'), "Reporte #{$video->report_id} (video)");
            }
        });

        $types = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Orden de trabajo'];
        $this->sheet('Remitos', ['ID', 'Número', 'Fecha', 'Tipo', 'Edificio ID', 'Edificio', 'Técnico', 'Período', 'Orden ID', 'Realizado', 'Trabajo realizado', 'Firmó (técnico)', 'Firmó (cliente)', 'Compartido con el cliente'],
            $this->scoped(DeliveryNote::class), fn ($n) => [$n->id, $n->number, $n->created_at, $types[$n->assignment_type] ?? $n->assignment_type, $n->building_id, $this->name('buildings', $n->building_id), $this->name('users', $n->user_id),
                $n->month ? str_pad((string) $n->month, 2, '0', STR_PAD_LEFT).'/'.$n->year : null, $n->work_order_id, (bool) $n->performed, $n->description, $n->signature_name, $n->client_signature_name, (bool) $n->shared_with_client]);

        $this->sheet('Presupuestos', ['ID', 'Fecha*', 'Cliente', 'Edificio ID', 'Edificio', 'Equipo', 'Título', 'Estado', 'Total', 'Válido hasta*', 'Descripción', 'Condiciones', 'Observaciones', 'Compartido con el cliente', 'Eliminado'],
            $this->scoped(Quote::class, true), fn ($q) => [$q->id, $q->issued_at ?? $q->created_at, $this->name('clients', $q->client_id), $q->building_id, $this->name('buildings', $q->building_id), $q->unit, $q->title,
                $q->displayStatusLabel(), $this->money($q->amount), $q->valid_until, $q->description, $q->conditions, $q->notes, (bool) $q->shared_with_client, $q->deleted_at !== null]);

        $this->sheet('Ítems de presupuestos', ['Presupuesto ID', 'Concepto', 'Detalle', 'Cantidad', 'Precio unitario', 'Subtotal'],
            $this->scoped(QuoteItem::class), fn ($i) => [$i->quote_id, $i->concept, $i->description, (float) $i->quantity, $this->money($i->unit_price), $this->money($i->subtotal)]);

        $this->sheet('Stock', ['ID', 'Código', 'Material', 'Descripción', 'Unidad', 'Costo', 'Stock actual', 'Stock mínimo', 'Activo', 'Eliminado'],
            $this->scoped(StockItem::class, true), fn ($s) => [$s->id, $s->code, $s->name, $s->description, $s->unit, $this->money($s->cost), (float) $s->current_stock, (float) $s->min_stock, (bool) $s->is_active, $s->deleted_at !== null]);

        $movements = ['in' => 'Entrada', 'out' => 'Salida', 'adjustment' => 'Ajuste'];
        $this->sheet('Movimientos de stock', ['ID', 'Fecha', 'Material', 'Tipo', 'Cantidad', 'Saldo', 'Usuario', 'Orden ID', 'Motivo'],
            $this->scoped(StockMovement::class), fn ($m) => [$m->id, $m->occurred_at ?? $m->created_at, $this->name('stock', $m->stock_item_id), $movements[$m->type] ?? $m->type, (float) $m->quantity, (float) $m->balance_after, $this->name('users', $m->user_id), $m->work_order_id, $m->reason]);

        $this->sheet('Cobranzas', ['ID', 'Cliente', 'Edificio', 'Concepto', 'Origen', 'Período*', 'Importe', 'Pagado', 'Saldo', 'Vencimiento*', 'Estado', 'Notas'],
            $this->scoped(Receivable::class), fn ($r) => [$r->id, $this->name('clients', $r->client_id), $this->name('buildings', $r->building_id), $r->concept, Receivable::SOURCES[$r->source] ?? $r->source, $r->period_start,
                $this->money($r->amount), $this->money($r->paid_amount), $this->money($r->balance()), $r->due_date, $r->displayStatusLabel(), $r->notes]);

        $this->sheet('Pagos recibidos', ['ID', 'Cobro ID', 'Fecha*', 'Importe', 'Medio', 'Notas', 'Registró'],
            $this->scoped(ReceivablePayment::class), fn ($p) => [$p->id, $p->receivable_id, $p->paid_at, $this->money($p->amount), ReceivablePayment::METHODS[$p->method] ?? $p->method, $p->notes, $this->name('users', $p->user_id)]);
    }

    private function writeFilesIndex(): void
    {
        if ($this->files === [] && $this->warnings === []) {
            return;
        }

        $this->writer->addNewSheetAndMakeItCurrent();
        $this->writer->getCurrentSheet()->setName('Archivos');
        $this->writer->addRow(new Row([new StringCell('Archivo en el ZIP', $this->header), new StringCell('Registro', $this->header), new StringCell('Estado', $this->header)]));

        foreach ($this->files as [$inZip, , $record]) {
            $this->writer->addRow(new Row([$this->cell($inZip), $this->cell($record), $this->cell('Incluido')]));
        }
        foreach ($this->warnings as $warning) {
            $this->writer->addRow(new Row([new EmptyCell(null, null), $this->cell($warning), $this->cell('No incluido')]));
        }

        $this->categories['Archivos adjuntos'] = count($this->files);
    }

    private function readme(Company $company): string
    {
        $lines = [
            'Exportación de datos de '.$company->name.' — Ascento',
            'Generada el '.now()->format('d/m/Y H:i').'.',
            '',
            'Qué es: una copia de los datos de negocio de tu empresa para que los tengas y los puedas abrir con Excel',
            '(o LibreOffice / Google Sheets). No es un backup del servidor ni sirve para "restaurar" Ascento.',
            '',
            'Contenido:',
            '- datos-*.xlsx: una hoja por tipo de dato. Los ID permiten relacionar hojas (por ejemplo, "Edificio ID").',
            '- adjuntos/reportes: fotos de los reportes. adjuntos/videos: videos de los reportes. adjuntos/documentos: planos, manuales, certificados y fotos de los ascensores.',
            '- La hoja "Archivos" indica a qué registro corresponde cada archivo y cuáles no se pudieron incluir.',
            '',
            'No incluye: contraseñas, tokens de acceso, firmas dibujadas, datos de pago de la suscripción ni datos de otras empresas.',
            '',
            'Cantidad de registros por hoja:',
        ];

        foreach ($this->categories as $sheet => $count) {
            $lines[] = '- '.$sheet.': '.$count;
        }

        if ($this->warnings) {
            $lines[] = '';
            $lines[] = 'Archivos que no se pudieron incluir: '.count($this->warnings).' (ver hoja "Archivos").';
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
