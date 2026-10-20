<?php

namespace App\Services\Clients;

use App\Enums\PlanLimit;
use App\Models\Client;
use App\Models\Company;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Importación de clientes desde .xlsx o .csv (nombre, persona de contacto,
 * teléfono, correo y notas).
 *
 * - Lee con OpenSpout: no ejecuta macros ni fórmulas. Una celda con fórmula
 *   se rechaza (no se usa su resultado ni su texto).
 * - Nunca recibe la empresa del archivo ni del navegador: la de destino es la
 *   del usuario autenticado (BelongsToCompany) y todas las consultas de
 *   duplicados se filtran por ella.
 * - No modifica clientes existentes ni crea otros registros.
 * - Un duplicado seguro (mismo correo, o mismo nombre con el mismo teléfono o
 *   correo, o una fila repetida del archivo) no se importa nunca, tampoco si
 *   aparece entre la vista previa y la confirmación. Los posibles duplicados
 *   los decide la persona.
 * - Importa todo o nada, dentro de una transacción, verificando el límite del
 *   plan antes de guardar.
 */
final class ClientImport
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 1000;

    public const FIELDS = [
        'name' => 'Nombre',
        'contact_person' => 'Persona de contacto',
        'phone' => 'Teléfono',
        'email' => 'Correo electrónico',
        'notes' => 'Notas',
    ];

    /** Largo máximo de cada campo (el de la base; las notas, como en el formulario). */
    private const MAX_LENGTH = ['name' => 255, 'contact_person' => 255, 'phone' => 255, 'email' => 255, 'notes' => 5000];

    /** Encabezados reconocidos (ya normalizados) para cada campo. */
    private const ALIASES = [
        'name' => ['nombre', 'cliente', 'nombre cliente', 'nombre del cliente', 'razon social', 'name'],
        'contact_person' => ['persona contacto', 'persona de contacto', 'contacto', 'responsable', 'contact person'],
        'phone' => ['telefono', 'tel', 'celular', 'movil', 'phone', 'telefono contacto'],
        'email' => ['email', 'e mail', 'mail', 'correo', 'correo electronico'],
        'notes' => ['notas', 'nota', 'observaciones', 'observacion', 'comentarios', 'notes'],
    ];

    /** Encabezados de la plantilla. */
    public const TEMPLATE_HEADERS = ['nombre', 'persona_contacto', 'telefono', 'email', 'notas'];

    public static function normalizeHeader(?string $value): string
    {
        $value = Str::lower(Str::ascii(trim((string) $value)));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    public static function normalizeName(?string $value): string
    {
        return self::normalizeHeader($value);
    }

    /** Solo dígitos y sin ceros iniciales («011 4567-8900» = «11 4567 8900»), para comparar. */
    public static function phoneDigits(?string $value): string
    {
        return ltrim(preg_replace('/\D+/', '', (string) $value), '0');
    }

    /**
     * Lee el archivo: encabezados y filas (texto). Lanza ValidationException
     * con un mensaje claro si el archivo no sirve.
     *
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>, formulas: list<int>}>}
     */
    public function read(string $path, string $originalName): array
    {
        $ext = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $fail = fn (string $m) => throw ValidationException::withMessages(['file' => $m]);

        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            $fail('El archivo tiene que ser .xlsx o .csv.');
        }
        if (! is_file($path) || filesize($path) === 0) {
            $fail('El archivo está vacío.');
        }
        if (filesize($path) > self::MAX_BYTES) {
            $fail('El archivo supera los 2 MB.');
        }

        // El contenido tiene que coincidir con la extensión (no alcanza con el nombre).
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if ($ext === 'xlsx' && ! str_starts_with($head, "PK\x03\x04")) {
            $fail('El archivo no es un Excel (.xlsx) válido.');
        }
        if ($ext === 'csv' && (str_contains($head, "\0") || str_starts_with($head, "PK\x03\x04"))) {
            $fail('El archivo no es un CSV de texto válido.');
        }

        try {
            if ($ext === 'csv') {
                $options = new CsvOptions;
                $firstLine = strtok(str_replace("\r", '', $head), "\n") ?: '';
                $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
                arsort($counts);
                $options->FIELD_DELIMITER = (string) array_key_first($counts);
                if (! mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
                    $options->ENCODING = 'Windows-1252';
                }
                $reader = new CsvReader($options);
            } else {
                $reader = new XlsxReader;
            }
            $reader->open($path);

            $headers = null;
            $rows = [];
            $line = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = [];
                    $formulas = [];
                    foreach ($row->getCells() as $i => $cell) {
                        if ($cell instanceof Cell\FormulaCell) {
                            $formulas[] = $i;
                            $cells[] = '';

                            continue;
                        }
                        $cells[] = self::text($cell);
                    }
                    if ($headers === null) {
                        if (array_filter($cells, fn ($c) => $c !== '') === []) {
                            continue;
                        }
                        $headers = $cells;

                        continue;
                    }
                    if (array_filter($cells, fn ($c) => $c !== '') === [] && $formulas === []) {
                        continue; // fila vacía
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        $fail('El archivo tiene más de '.self::MAX_ROWS.' filas. Dividilo en varios archivos.');
                    }
                    $rows[] = ['line' => $line, 'cells' => $cells, 'formulas' => $formulas];
                }
                break; // solo la primera hoja
            }
            $reader->close();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $fail('No pudimos leer el archivo. Revisá que sea un .xlsx o .csv válido.');
        }

        if ($headers === null) {
            $fail('El archivo está vacío.');
        }
        if ($rows === []) {
            $fail('El archivo no tiene filas con clientes debajo de los encabezados.');
        }

        return ['headers' => array_map(fn ($h) => trim((string) $h), $headers), 'rows' => $rows];
    }

    /** Texto de una celda, sin perder ceros ni prefijos de teléfonos guardados como texto. */
    private static function text(Cell $cell): string
    {
        $value = $cell->getValue();

        return match (true) {
            $value === null => '',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => sprintf('%.0f', $value),
            is_int($value) || is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('d/m/Y'),
            default => trim((string) $value),
        };
    }

    /**
     * Relaciona columnas del archivo con los campos (null si no se reconoce).
     *
     * @param  list<string>  $headers
     * @return array<string, ?int> campo => índice de columna
     */
    public function guessMapping(array $headers): array
    {
        $map = array_fill_keys(array_keys(self::FIELDS), null);
        foreach ($headers as $i => $header) {
            $norm = self::normalizeHeader($header);
            foreach (self::ALIASES as $field => $aliases) {
                if ($map[$field] === null && in_array($norm, $aliases, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * Valida las filas y busca duplicados (en el archivo y entre los clientes
     * de ESTA empresa). No guarda nada.
     *
     * @param  array<string, ?int>  $mapping
     * @return list<array{line: int, data: array, errors: list<string>, match: ?string, matchLevel: ?string, selected: bool}>
     */
    public function analyze(Company $company, array $rows, array $mapping): array
    {
        if (($mapping['name'] ?? null) === null) {
            throw ValidationException::withMessages(['mapping' => 'Elegí qué columna tiene el nombre del cliente.']);
        }

        // Clientes de la empresa (también desactivados): una consulta.
        $existing = Client::withoutGlobalScopes()->withTrashed()->where('company_id', $company->id)
            ->get(['id', 'name', 'phone', 'email', 'deleted_at']);
        [$byName, $byEmail, $byPhone] = self::index($existing);

        $seenName = $seenEmail = $seenPhone = $seenNamePhone = [];
        $out = [];

        foreach ($rows as $row) {
            $get = fn (string $field) => ($i = $mapping[$field] ?? null) === null ? '' : trim((string) ($row['cells'][$i] ?? ''));
            $data = [
                'name' => $get('name'),
                'contact_person' => $get('contact_person'),
                'phone' => $get('phone'),
                'email' => Str::lower($get('email')),
                'notes' => $get('notes'),
            ];
            $errors = [];
            foreach ($mapping as $field => $i) {
                if ($i !== null && in_array($i, $row['formulas'], true)) {
                    $errors[] = self::FIELDS[$field].': tiene una fórmula (cargá el valor como texto).';
                }
            }
            if ($data['name'] === '') {
                $errors[] = 'Falta el nombre.';
            }
            foreach (self::MAX_LENGTH as $field => $max) {
                if (mb_strlen($data[$field]) > $max) {
                    $errors[] = self::FIELDS[$field].': es demasiado largo.';
                }
            }
            if ($data['email'] !== '' && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'El correo no es válido.';
            }

            $name = self::normalizeName($data['name']);
            $digits = self::phoneDigits($data['phone']);
            $match = null;
            $level = null;

            if ($errors === []) {
                // 1) Con clientes que ya tenés.
                $sameName = $name !== '' ? ($byName[$name] ?? collect()) : collect();
                $phoneHit = strlen($digits) >= 6 ? $byPhone->get($digits) : null;

                if ($clear = self::clearMatch($data, $byName, $byEmail)) {
                    [$level, $match] = ['clear', $clear];
                } elseif ($sameName->isNotEmpty()) {
                    $hit = $sameName->first();
                    [$level, $match] = ['possible', 'Ya tenés un cliente con el mismo nombre'.($hit->deleted_at ? ' (desactivado)' : '').'. Puede ser otro: revisalo.'];
                } elseif ($phoneHit) {
                    [$level, $match] = ['possible', 'El teléfono coincide con «'.$phoneHit->name.'».'];
                }

                // 2) Dentro del mismo archivo (solo si no coincide ya con uno existente).
                if ($level === null) {
                    $prev = ($data['email'] !== '' ? ($seenEmail[$data['email']] ?? null) : null)
                        ?? (strlen($digits) >= 6 ? ($seenNamePhone[$name.'|'.$digits] ?? null) : null);
                    if ($prev) {
                        [$level, $match] = ['clear', 'Repite la fila '.$prev.' del archivo.'];
                    } elseif (isset($seenName[$name])) {
                        [$level, $match] = ['possible', 'Mismo nombre que la fila '.$seenName[$name].' del archivo. Puede ser otro: revisalo.'];
                    } elseif (strlen($digits) >= 6 && isset($seenPhone[$digits])) {
                        [$level, $match] = ['possible', 'Mismo teléfono que la fila '.$seenPhone[$digits][0].' del archivo.'];
                    }
                }

                $seenName[$name] ??= $row['line'];
                if ($data['email'] !== '') {
                    $seenEmail[$data['email']] ??= $row['line'];
                }
                if (strlen($digits) >= 6) {
                    $seenPhone[$digits][] = $row['line'];
                    $seenNamePhone[$name.'|'.$digits] ??= $row['line'];
                }
            }

            $out[] = [
                'line' => $row['line'],
                'data' => $data,
                'errors' => $errors,
                'match' => $match,
                'matchLevel' => $level,
                // Lo nuevo se importa; lo que coincide queda para revisar (sin tildar).
                'selected' => $errors === [] && $level === null,
            ];
        }

        return $out;
    }

    /** ¿Se puede tildar esta fila? Sin errores y sin ser un duplicado seguro. */
    public static function selectable(array $row): bool
    {
        return $row['errors'] === [] && ($row['matchLevel'] ?? null) !== 'clear';
    }

    /**
     * Columnas del archivo que no se van a importar (para avisarlo: nada se
     * pierde sin que la persona lo sepa).
     *
     * @param  list<string>  $headers
     * @param  array<string, ?int>  $mapping
     * @return list<string>
     */
    public static function ignoredColumns(array $headers, array $mapping): array
    {
        $used = array_filter($mapping, fn ($i) => $i !== null);

        return array_values(array_filter(
            array_map(fn ($h, $i) => in_array($i, $used, true) || trim($h) === '' ? null : $h, $headers, array_keys($headers)),
        ));
    }

    /** Índices de clientes existentes por nombre normalizado, correo y teléfono. */
    private static function index($clients): array
    {
        return [
            $clients->groupBy(fn ($c) => self::normalizeName($c->name)),
            $clients->filter(fn ($c) => filled($c->email))->keyBy(fn ($c) => Str::lower(trim($c->email))),
            $clients->filter(fn ($c) => strlen(self::phoneDigits($c->phone)) >= 6)->keyBy(fn ($c) => self::phoneDigits($c->phone)),
        ];
    }

    /** Motivo si la fila es un cliente que YA existe sin dudas (mismo correo, o mismo nombre y contacto). */
    private static function clearMatch(array $data, $byName, $byEmail): ?string
    {
        $name = self::normalizeName($data['name']);
        $digits = self::phoneDigits($data['phone']);
        $sameName = $name !== '' ? ($byName[$name] ?? collect()) : collect();
        $emailHit = $data['email'] !== '' ? $byEmail->get($data['email']) : null;
        $nameAndContact = $sameName->first(fn ($c) => ($data['email'] !== '' && Str::lower(trim((string) $c->email)) === $data['email'])
            || (strlen($digits) >= 6 && self::phoneDigits($c->phone) === $digits));
        $hit = $nameAndContact ?? $emailHit;

        return $hit ? 'Ya tenés a «'.$hit->name.'»'.($hit->deleted_at ? ' (desactivado)' : '').' con '.($emailHit ? 'el mismo correo' : 'los mismos datos').'.' : null;
    }

    /**
     * Importa las filas elegidas, todas o ninguna. Vuelve a validar cada
     * fila y el límite del plan dentro de la transacción.
     *
     * @param  list<array>  $rows  filas analizadas (solo se guardan las seleccionadas, sin errores y que no sean duplicados seguros)
     * @return array{imported: int, skipped: int, rejected: int, late: array<int, string>}
     */
    public function import(Company $company, array $rows, string $type): array
    {
        if (! array_key_exists($type, Client::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Elegí un tipo de cliente válido.']);
        }

        $chosen = array_values(array_filter($rows, fn ($r) => $r['selected'] && self::selectable($r)));
        $rejected = count(array_filter($rows, fn ($r) => $r['errors'] !== []));
        $late = [];

        DB::transaction(function () use ($company, &$chosen, $type, &$late) {
            // Serializa importaciones de la misma empresa y verifica el cupo del plan antes de guardar.
            Company::whereKey($company->id)->lockForUpdate()->first();

            // Clientes cargados entre la vista previa y la confirmación (otra
            // persona, otra pestaña): un duplicado seguro se omite, no se crea.
            [$byName, $byEmail] = self::index(Client::withoutGlobalScopes()->withTrashed()->where('company_id', $company->id)->get(['id', 'name', 'phone', 'email', 'deleted_at']));
            $chosen = array_values(array_filter($chosen, function ($row) use ($byName, $byEmail, &$late) {
                if ($reason = self::clearMatch($row['data'], $byName, $byEmail)) {
                    $late[$row['line']] = $reason;

                    return false;
                }

                return true;
            }));

            $guard = PlanGuard::for($company->fresh());
            $max = $guard->limit(PlanLimit::Clients);
            $used = $guard->usage(PlanLimit::Clients);
            if ($max !== null && $used + count($chosen) > $max) {
                throw ValidationException::withMessages(['rows' => "Tu plan permite {$max} clientes y ya tenés {$used}: podés importar hasta ".max(0, $max - $used).'. Destildá algunos o cambiá de plan.']);
            }

            foreach ($chosen as $row) {
                $d = $row['data'];
                // Solo estos campos: la empresa la pone BelongsToCompany con el usuario autenticado.
                Client::create([
                    'name' => $d['name'],
                    'contact_person' => $d['contact_person'] !== '' ? $d['contact_person'] : null,
                    'phone' => $d['phone'] !== '' ? $d['phone'] : null,
                    'email' => $d['email'] !== '' ? $d['email'] : null,
                    'notes' => ($d['notes'] ?? '') !== '' ? $d['notes'] : null,
                    'type' => $type,
                    'is_active' => true,
                ]);
            }
        });

        return ['imported' => count($chosen), 'skipped' => count($rows) - count($chosen) - $rejected, 'rejected' => $rejected, 'late' => $late];
    }
}
