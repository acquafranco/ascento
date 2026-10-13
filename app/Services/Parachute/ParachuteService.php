<?php

namespace App\Services\Parachute;

use App\Enums\PermissionArea;
use App\Filament\Pages\ParachuteTestsPage;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\ParachuteSchedule;
use App\Models\ParachuteTest;
use App\Models\ParachuteTestFile;
use App\Models\User;
use App\Notifications\App\ParachuteReminderNotification;
use App\Services\Notifications\Notifier;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pruebas de paracaídas (Etapa 7).
 *
 * - Periodicidad: la del ascensor; si no tiene, la de su cliente; si
 *   tampoco, ninguna (Ascento no asume una periodicidad universal ni
 *   requisitos legales).
 * - Prevista: la próxima fecha (última prueba SATISFACTORIA + periodicidad).
 *   Realizada: un registro. No satisfactoria: un registro con ese
 *   resultado, que deja el ascensor en «No satisfactoria» hasta que haya una
 *   prueba satisfactoria posterior.
 * - Un recordatorio nunca cuenta como prueba realizada: solo los registros.
 *
 * Toda escritura exige «Inspecciones y reportes: gestionar» (o ser el dueño)
 * y que el ascensor sea de la empresa actual.
 */
class ParachuteService
{
    public const OVERDUE = 'overdue';

    public const UPCOMING = 'upcoming';

    public const FAILED = 'failed';

    public const NO_RECORD = 'no_record';

    public const UNCONFIGURED = 'unconfigured';

    public const OK = 'ok';

    public const STATUSES = [
        self::OVERDUE => 'Vencidas',
        self::UPCOMING => 'Próximas',
        self::FAILED => 'No satisfactorias',
        self::NO_RECORD => 'Sin pruebas registradas',
        self::UNCONFIGURED => 'Sin periodicidad definida',
        self::OK => 'Al día',
    ];

    public const MAX_INTERVAL_MONTHS = 120;

    public const MAX_NOTICE_DAYS = 365;

    public const MAX_KB = 20480;

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private Notifier $notifier) {}

    /* ---------------------------------------------------------- PERMISOS */

    private function authorizeManage(?User $actor): void
    {
        abort_unless(Access::can($actor, PermissionArea::Inspections, PermissionArea::MANAGE), 403);
    }

    /** Con sesión, solo registros de la empresa actual (otra empresa: 404). */
    private function ensureCompany(int $companyId): void
    {
        $current = CompanyContext::currentId();
        abort_if($current === null || $current !== $companyId, 404);
    }

    /**
     * Inspector o responsable posible: el dueño, un técnico, o un
     * administrativo vigente con acceso a Inspecciones. De esa empresa.
     */
    public function eligible(User $user, int $companyId): bool
    {
        if ((int) $user->company_id !== $companyId || $user->trashed() || $user->isSuperAdmin()) {
            return false;
        }

        return Access::isOwner($user)
            || $user->role === 'technician'
            || (Access::isActiveStaff($user) && Access::can($user, PermissionArea::Inspections));
    }

    /** @return array<int, string> id => nombre */
    public function people(int $companyId): array
    {
        return User::where('company_id', $companyId)->whereIn('role', ['admin', 'technician', User::ROLE_STAFF])->where('is_super_admin', false)
            ->orderBy('name')->get()
            ->filter(fn (User $user) => $this->eligible($user, $companyId))
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])->all();
    }

    /* ------------------------------------------------------------- ESTADO */

    /**
     * Estado de cada ascensor activo de la empresa (sin scopes: sirve igual
     * en el panel y en el scheduler).
     *
     * @return Collection<int, array{elevator: Elevator, building: ?Building, client: ?Client, schedule: ?ParachuteSchedule, interval: ?int, interval_source: ?string, notice_days: int, last: ?ParachuteTest, last_ok: ?ParachuteTest, next_due: ?CarbonInterface, status: string}>
     */
    public function overview(int $companyId): Collection
    {
        $elevators = Elevator::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)
            ->with(['building' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select('id', 'name', 'client_id', 'deleted_at', 'company_id')])
            ->orderBy('building_id')->orderBy('label')->get()
            ->filter(fn (Elevator $e) => $e->building && $e->building->deleted_at === null)
            ->values();

        $clients = Client::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('id', $elevators->pluck('building.client_id')->filter()->unique())
            ->get(['id', 'name', 'parachute_interval_months'])->keyBy('id');

        $schedules = ParachuteSchedule::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('elevator_id', $elevators->pluck('id'))->with(['inspector:id,name', 'responsible:id,name'])->get()->keyBy('elevator_id');

        // Pocas pruebas por equipo (una o dos por año): se cargan solo las columnas necesarias.
        $tests = ParachuteTest::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('elevator_id', $elevators->pluck('id'))
            ->orderByDesc('performed_on')->orderByDesc('id')
            ->get(['id', 'elevator_id', 'performed_on', 'result', 'inspector_name', 'performed_by_id', 'performed_by_name'])
            ->groupBy('elevator_id');

        return $elevators->map(function (Elevator $elevator) use ($clients, $schedules, $tests) {
            $schedule = $schedules->get($elevator->id);
            $client = $clients->get($elevator->building->client_id);
            $own = $tests->get($elevator->id, collect());

            return $this->evaluate($elevator, $client, $schedule, $own->first(), $own->first(fn (ParachuteTest $t) => $t->isSatisfactory()));
        });
    }

    /** Estado de un ascensor a partir de su política y sus pruebas. */
    public function evaluate(Elevator $elevator, ?Client $client, ?ParachuteSchedule $schedule, ?ParachuteTest $last, ?ParachuteTest $lastOk): array
    {
        $interval = $schedule?->interval_months ?: ($client?->parachute_interval_months ?: null);
        $source = $schedule?->interval_months ? 'elevator' : ($client?->parachute_interval_months ? 'client' : null);
        $notice = $schedule?->notice_days ?? ParachuteSchedule::DEFAULT_NOTICE_DAYS;
        $next = ($interval && $lastOk) ? $lastOk->performed_on->copy()->addMonthsNoOverflow($interval) : null;
        $today = today();

        $status = match (true) {
            $last !== null && ! $last->isSatisfactory() => self::FAILED,
            $interval === null => self::UNCONFIGURED,
            $lastOk === null => self::NO_RECORD,
            $next->lt($today) => self::OVERDUE,
            $next->lte($today->copy()->addDays($notice)) => self::UPCOMING,
            default => self::OK,
        };

        return [
            'elevator' => $elevator, 'building' => $elevator->building, 'client' => $client, 'schedule' => $schedule,
            'interval' => $interval, 'interval_source' => $source, 'notice_days' => $notice,
            'last' => $last, 'last_ok' => $lastOk, 'next_due' => $next, 'status' => $status,
        ];
    }

    /* ------------------------------------------------------ CONFIGURACIÓN */

    /** Periodicidad del ascensor, aviso previo, inspector y responsable. */
    public function configure(User $actor, Elevator $elevator, array $data): ParachuteSchedule
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $elevator->company_id);
        $companyId = (int) $elevator->company_id;

        $interval = $this->interval($data['interval_months'] ?? null, 'interval_months');
        $notice = $data['notice_days'] ?? ParachuteSchedule::DEFAULT_NOTICE_DAYS;
        if (! is_numeric($notice) || (int) $notice < 0 || (int) $notice > self::MAX_NOTICE_DAYS) {
            throw ValidationException::withMessages(['notice_days' => 'Entre 0 y '.self::MAX_NOTICE_DAYS.' días.']);
        }

        foreach (['inspector_id', 'responsible_id'] as $field) {
            if (filled($data[$field] ?? null)) {
                $person = User::withTrashed()->find((int) $data[$field]);
                if (! $person || ! $this->eligible($person, $companyId)) {
                    throw ValidationException::withMessages([$field => 'Elegí una persona de la empresa con acceso a inspecciones.']);
                }
            }
        }

        $schedule = ParachuteSchedule::withoutGlobalScopes()->where('elevator_id', $elevator->id)->first() ?? new ParachuteSchedule;
        $schedule->forceFill([
            'company_id' => $companyId,
            'elevator_id' => $elevator->id,
            'interval_months' => $interval,
            'notice_days' => (int) $notice,
            'inspector_id' => filled($data['inspector_id'] ?? null) ? (int) $data['inspector_id'] : null,
            'responsible_id' => filled($data['responsible_id'] ?? null) ? (int) $data['responsible_id'] : null,
        ])->save();

        return $schedule;
    }

    /** Periodicidad según la política del cliente (sus ascensores sin una propia). */
    public function setClientInterval(User $actor, Client $client, mixed $months): Client
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $client->company_id);

        $client->forceFill(['parachute_interval_months' => $this->interval($months, 'parachute_interval_months')])->save();

        return $client;
    }

    private function interval(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1 || (int) $value > self::MAX_INTERVAL_MONTHS) {
            throw ValidationException::withMessages([$field => 'Entre 1 y '.self::MAX_INTERVAL_MONTHS.' meses (o vacío, sin definir).']);
        }

        return (int) $value;
    }

    /* ----------------------------------------------------------- PRUEBAS */

    /**
     * @param  list<UploadedFile>  $files
     */
    public function record(User $actor, array $data, array $files = []): ParachuteTest
    {
        $this->authorizeManage($actor);

        $elevator = Elevator::withoutGlobalScopes()->find((int) ($data['elevator_id'] ?? 0));
        if (! $elevator || (int) $elevator->company_id !== CompanyContext::currentId() || ! $elevator->is_active) {
            throw ValidationException::withMessages(['elevator_id' => 'Elegí un ascensor activo de la empresa.']);
        }

        $test = new ParachuteTest;
        $test->forceFill(['company_id' => $elevator->company_id, 'elevator_id' => $elevator->id, 'building_id' => $elevator->building_id, 'recorded_by' => $actor->id]
            + $this->validated((int) $elevator->company_id, $data));

        DB::transaction(function () use ($test, $files, $actor) {
            $test->save();

            foreach ($files as $file) {
                $this->attach($actor, $test, $file);
            }
        });

        return $test;
    }

    public function update(User $actor, ParachuteTest $test, array $data): ParachuteTest
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $test->company_id);

        // El ascensor de una prueba no cambia: se corrige fecha, resultado y responsables.
        $test->forceFill($this->validated((int) $test->company_id, $data))->save();

        return $test;
    }

    private function validated(int $companyId, array $data): array
    {
        $errors = [];

        try {
            $date = Carbon::parse((string) ($data['performed_on'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || empty($data['performed_on'])) {
            $errors['performed_on'] = 'Indicá la fecha de la prueba.';
        } elseif ($date->gt(today())) {
            $errors['performed_on'] = 'Una prueba realizada no puede tener fecha futura: la próxima prevista se calcula sola.';
        }

        if (! array_key_exists($data['result'] ?? '', ParachuteTest::RESULTS)) {
            $errors['result'] = 'Elegí el resultado.';
        }

        $performer = null;
        if (filled($data['performed_by_id'] ?? null)) {
            $performer = User::withTrashed()->find((int) $data['performed_by_id']);
            if (! $performer || ! $this->eligible($performer, $companyId)) {
                $errors['performed_by_id'] = 'Elegí una persona de la empresa.';
            }
        }

        $name = Str::limit(trim((string) ($data['performed_by_name'] ?? '')), 120, '');
        if (! $performer && $name === '') {
            $errors['performed_by_id'] = 'Indicá quién la realizó.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'performed_on' => $date->toDateString(),
            'result' => $data['result'],
            'performed_by_id' => $performer?->id,
            'performed_by_name' => $performer ? null : $name,
            'inspector_name' => Str::limit(trim((string) ($data['inspector_name'] ?? '')), 120, '') ?: null,
            'inspector_registration' => Str::limit(trim((string) ($data['inspector_registration'] ?? '')), 60, '') ?: null,
            'observations' => Str::limit(trim((string) ($data['observations'] ?? '')), 5000, '') ?: null,
        ];
    }

    /** Documento o foto de la prueba (tipo por contenido, nombre aleatorio, disco privado). */
    public function attach(User $actor, ParachuteTest $test, UploadedFile $file): ParachuteTestFile
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $test->company_id);

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['files' => 'Solo PDF o imágenes (JPG, PNG, WEBP).']);
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['files' => 'Cada archivo puede pesar hasta 20 MB.']);
        }

        $path = 'elevators/'.$test->company_id.'/parachute/'.Str::random(40).'.'.self::MIMES[$mime];
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        try {
            $row = new ParachuteTestFile;
            $row->forceFill([
                'company_id' => $test->company_id,
                'parachute_test_id' => $test->id,
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
                'mime' => $mime,
                'size' => $file->getSize(),
                'uploaded_by' => $actor->id,
            ])->save();

            return $row;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /* ------------------------------------------------------ RECORDATORIOS */

    /**
     * Avisos de pruebas próximas o vencidas (scheduler, una vez por día).
     * Destinatarios: los dueños de la empresa, el inspector asignado y el
     * responsable (si todavía pueden recibirlo). Uno por ascensor, motivo,
     * fecha prevista y persona: repetir el comando no duplica, y si la fecha
     * cambia (nueva prueba o nueva periodicidad) corresponde un aviso nuevo.
     */
    public function sendReminders(): int
    {
        $sent = 0;

        Company::where('is_active', true)->each(function (Company $company) use (&$sent) {
            if (! $company->hasActiveAccess()) {
                return;
            }

            $due = $this->overview($company->id)->filter(fn (array $row) => in_array($row['status'], [self::UPCOMING, self::OVERDUE], true));
            if ($due->isEmpty()) {
                return;
            }

            $owners = User::where('company_id', $company->id)->where('role', 'admin')->where('is_super_admin', false)->get();
            $panelPath = parse_url(ParachuteTestsPage::getUrl(panel: 'ascensores_app'), PHP_URL_PATH);
            $technicianPath = route('buildings.index', ['company' => $company->slug], false);

            $this->notifier->batch(function (Notifier $notifier) use ($due, $owners, $company, $panelPath, $technicianPath, &$sent) {
                foreach ($due as $row) {
                    $recipients = $owners->keyBy('id');
                    foreach (['inspector_id', 'responsible_id'] as $field) {
                        $id = $row['schedule']?->{$field};
                        if ($id && ($person = User::find($id)) && $this->eligible($person, $company->id)) {
                            $recipients->put($person->id, $person);
                        }
                    }

                    foreach ($recipients as $user) {
                        $path = $user->role === 'technician' ? $technicianPath : $panelPath;
                        $sent += (int) (bool) $notifier->sendTo($user, new ParachuteReminderNotification(
                            $row['status'], (int) $row['elevator']->id, $row['elevator']->label, $row['building']?->name ?? '', $row['next_due']->toDateString(), $path,
                        ), $company->id);
                    }
                }
            });
        });

        return $sent;
    }

    /**
     * Estado de los avisos por ascensor (una consulta): último aviso, a
     * cuántos se envió y cuántos lo leyeron. Sale de los avisos internos
     * (notifications), que ya registran envío y lectura.
     *
     * @return Collection<int, array{last: CarbonInterface, sent: int, read: int}>
     */
    public function reminderSummary(int $companyId): Collection
    {
        return DB::table('notifications')->where('company_id', $companyId)->where('dedupe_key', 'like', 'parachute:%')
            ->get(['dedupe_key', 'created_at', 'read_at'])
            ->groupBy(fn ($row) => (int) explode(':', $row->dedupe_key)[1])
            ->map(function ($rows) {
                // El último aviso (misma clave: mismo motivo y fecha prevista, a cada destinatario).
                $key = $rows->sortByDesc('created_at')->first()->dedupe_key;
                $latest = $rows->where('dedupe_key', $key);

                return ['last' => Carbon::parse($latest->max('created_at')), 'sent' => $latest->count(), 'read' => $latest->whereNotNull('read_at')->count()];
            });
    }
}
