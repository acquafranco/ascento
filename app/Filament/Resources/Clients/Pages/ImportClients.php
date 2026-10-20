<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\PermissionArea;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Clients\ClientImport;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importar clientes desde Excel o CSV: subir → (relacionar columnas) →
 * vista previa con errores y posibles duplicados → confirmar → resumen.
 *
 * Solo quien puede GESTIONAR clientes. La empresa de destino es la del
 * contexto autenticado (nunca un dato del archivo o del navegador). Las filas
 * analizadas quedan en el servidor (caché, por usuario y empresa) y se
 * importan una sola vez: un reenvío no vuelve a crearlas.
 */
class ImportClients extends Page
{
    use WithFileUploads;

    protected static string $resource = ClientResource::class;

    protected string $view = 'filament.resources.clients.import';

    protected static ?string $title = 'Importar clientes';

    /** upload | map | preview | done */
    #[Locked]
    public string $step = 'upload';

    public $file = null;

    #[Locked]
    public string $token = '';

    #[Locked]
    public array $headers = [];

    /** campo => índice de columna (lo puede ajustar la persona; se valida). */
    public array $mapping = [];

    /** Líneas del archivo elegidas para importar. */
    public array $selected = [];

    public string $type = Client::DEFAULT_TYPE;

    #[Locked]
    public ?array $summary = null;

    public static function canAccess(array $parameters = []): bool
    {
        return CompanyContext::currentId() !== null && Access::can(auth()->user(), PermissionArea::Clients, PermissionArea::MANAGE);
    }

    private function company(): Company
    {
        abort_unless(static::canAccess(), 403);

        return Company::findOrFail(CompanyContext::currentId());
    }

    private function cacheKey(): string
    {
        return 'client-import:'.$this->company()->id.':'.auth()->id().':'.$this->token;
    }

    public function getSubheading(): ?string
    {
        return 'Cargá muchos clientes de una vez desde una planilla. Se importan nombre, persona de contacto, teléfono, correo y notas; no se tocan los clientes que ya tenés.';
    }

    public function downloadTemplate(): StreamedResponse
    {
        $this->company();

        return response()->streamDownload(function () {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(new Row(array_map(fn ($h) => new StringCell($h, null), ClientImport::TEMPLATE_HEADERS)));
            $writer->addRow(new Row(array_map(fn ($v) => new StringCell($v, null), ['Consorcio Ejemplo 123', 'María Pérez', '011 4567-8900', 'administracion@ejemplo.com', 'Pagan por transferencia'])));
            $writer->close();
        }, 'plantilla-clientes.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function analyzeFile(ClientImport $import): void
    {
        $company = $this->company();
        $this->validate(['file' => ['required', 'file', 'max:'.intdiv(ClientImport::MAX_BYTES, 1024)]], [
            'file.required' => 'Elegí un archivo .xlsx o .csv.',
            'file.max' => 'El archivo supera los 2 MB.',
        ]);

        $read = $import->read($this->file->getRealPath(), $this->file->getClientOriginalName());
        $this->token = Str::random(40);
        $this->headers = $read['headers'];
        $this->mapping = $import->guessMapping($read['headers']);
        Cache::put($this->cacheKey(), ['raw' => $read['rows'], 'rows' => null, 'hash' => hash_file('sha256', $this->file->getRealPath())], now()->addMinutes(30));
        $this->file = null;

        // Con el nombre reconocido sin dudas se va directo a la vista previa; si no, a relacionar columnas.
        $this->mapping['name'] !== null ? $this->buildPreview($import) : $this->step = 'map';
    }

    public function applyMapping(ClientImport $import): void
    {
        $this->company();
        foreach (array_keys(ClientImport::FIELDS) as $field) {
            $v = $this->mapping[$field] ?? null;
            $this->mapping[$field] = ($v === '' || $v === null) ? null : (int) $v;
            abort_unless($this->mapping[$field] === null || array_key_exists($this->mapping[$field], $this->headers), 422);
        }
        $used = array_filter($this->mapping, fn ($v) => $v !== null);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['mapping' => 'Cada columna se puede usar para un solo campo.']);
        }
        $this->buildPreview($import);
    }

    private function buildPreview(ClientImport $import): void
    {
        $payload = Cache::get($this->cacheKey());
        if (! $payload) {
            $this->restart('La vista previa venció. Volvé a subir el archivo.');

            return;
        }
        $rows = $import->analyze($this->company(), $payload['raw'], $this->mapping);
        Cache::put($this->cacheKey(), [...$payload, 'rows' => $rows], now()->addMinutes(30));
        $this->selected = array_values(array_map(fn ($r) => $r['line'], array_filter($rows, fn ($r) => $r['selected'])));
        $this->step = 'preview';
    }

    public function confirm(ClientImport $import): void
    {
        $company = $this->company();
        $lock = Cache::lock('client-import-run:'.$company->id, 30);
        if (! $lock->get()) {
            Notification::make()->title('Ya hay una importación en curso. Esperá unos segundos.')->warning()->send();

            return;
        }

        try {
            $payload = Cache::get($this->cacheKey());
            if (! $payload || ! is_array($payload['rows'] ?? null)) {
                // Reenvío de una importación ya hecha (o vencida): no se crea nada.
                $this->restart('Esta importación ya se procesó o venció. Revisá la lista de clientes antes de volver a importar.');

                return;
            }
            $selected = array_map('intval', $this->selected);
            // Un duplicado seguro no se importa aunque llegue tildado.
            $rows = array_map(fn ($r) => [...$r, 'selected' => ClientImport::selectable($r) && in_array($r['line'], $selected, true)], $payload['rows']);

            $result = $import->import($company, $rows, $this->type);
            Cache::forget($this->cacheKey());

            $late = $result['late'];
            $this->summary = $result + [
                'skippedRows' => array_values(array_map(fn ($r) => ['line' => $r['line'], 'name' => $r['data']['name'], 'reason' => $late[$r['line']] ?? $r['match'] ?? 'No la elegiste.'],
                    array_filter($rows, fn ($r) => $r['errors'] === [] && (! $r['selected'] || isset($late[$r['line']]))))),
                'rejectedRows' => array_values(array_map(fn ($r) => ['line' => $r['line'], 'name' => $r['data']['name'], 'reason' => implode(' ', $r['errors'])], array_filter($rows, fn ($r) => $r['errors'] !== []))),
            ];
            $this->step = 'done';
        } finally {
            $lock->release();
        }
    }

    /** Tildar todas las filas que se pueden importar (también los posibles duplicados) o ninguna. */
    public function selectAll(bool $all = true): void
    {
        $rows = Cache::get($this->cacheKey())['rows'] ?? [];
        $this->selected = $all ? array_values(array_map(fn ($r) => $r['line'], array_filter($rows, [ClientImport::class, 'selectable']))) : [];
    }

    /** Solo las filas nuevas (sin coincidencias), como al abrir la vista previa. */
    public function selectNew(): void
    {
        $rows = Cache::get($this->cacheKey())['rows'] ?? [];
        $this->selected = array_values(array_map(fn ($r) => $r['line'], array_filter($rows, fn ($r) => $r['selected'])));
    }

    public function restart(?string $message = null): void
    {
        if ($this->token !== '') {
            Cache::forget($this->cacheKey());
        }
        $this->reset('step', 'file', 'token', 'headers', 'mapping', 'selected', 'summary');
        $this->type = Client::DEFAULT_TYPE;
        if ($message) {
            Notification::make()->title($message)->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        $rows = $this->step === 'preview' ? (Cache::get($this->cacheKey())['rows'] ?? []) : [];

        return [
            'rows' => $rows,
            'counts' => [
                'valid' => count(array_filter($rows, fn ($r) => $r['errors'] === [] && $r['matchLevel'] === null)),
                'errors' => count(array_filter($rows, fn ($r) => $r['errors'] !== [])),
                'matches' => count(array_filter($rows, fn ($r) => $r['errors'] === [] && $r['matchLevel'] === 'possible')),
                'duplicates' => count(array_filter($rows, fn ($r) => $r['errors'] === [] && $r['matchLevel'] === 'clear')),
            ],
            'ignored' => $this->step === 'preview' ? ClientImport::ignoredColumns($this->headers, $this->mapping) : [],
            'types' => Client::TYPES,
            'fields' => ClientImport::FIELDS,
            'maxRows' => ClientImport::MAX_ROWS,
        ];
    }
}
