<?php

namespace App\Services\Repairs;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\Elevator;
use App\Models\RepairComponent;
use App\Models\RepairComponentFile;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Activity\ActivityLogger;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Componentes enviados a reparación (Etapa 8.4). Registrar, corregir,
 * cambiar de estado y adjuntar piden «Reparaciones: gestionar» (o el dueño),
 * siempre de la empresa actual: otra empresa → 404. Edificio, ascensor,
 * orden de trabajo y responsable se validan contra la empresa. El estado
 * solo avanza por RepairComponent::TRANSITIONS, bajo bloqueo de la fila.
 */
class RepairService
{
    public const MAX_KB = 20480;

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public const MAX_AMOUNT = 9_999_999_999.99;

    /** Campos que se cargan o corrigen con el formulario (el estado, no). */
    private const EDITABLE = ['building_id', 'elevator_id', 'work_order_id', 'component_type', 'description', 'brand', 'model', 'serial_number',
        'failure', 'sent_at', 'provider', 'follow_up_user_id', 'quote_amount', 'final_cost', 'expected_return_at', 'returned_at', 'notes'];

    private function authorizeManage(?User $actor): void
    {
        abort_unless(Access::can($actor, PermissionArea::Repairs, PermissionArea::MANAGE), 403);
    }

    private function ensureCompany(int $companyId): void
    {
        $current = CompanyContext::currentId();
        abort_if($current === null || $current !== $companyId, 404);
    }

    /** Responsable del seguimiento: el dueño, un técnico o un administrativo vigente con «Reparaciones». De esa empresa. */
    public function eligible(User $user, int $companyId): bool
    {
        if ((int) $user->company_id !== $companyId || $user->trashed() || $user->isSuperAdmin()) {
            return false;
        }

        return Access::isOwner($user)
            || $user->role === 'technician'
            || (Access::isActiveStaff($user) && Access::can($user, PermissionArea::Repairs));
    }

    /** @return array<int, string> id => nombre */
    public function people(int $companyId): array
    {
        return User::where('company_id', $companyId)->whereIn('role', ['admin', 'technician', User::ROLE_STAFF])->where('is_super_admin', false)
            ->orderBy('name')->get()
            ->filter(fn (User $user) => $this->eligible($user, $companyId))
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])->all();
    }

    /**
     * Alta: «Pendiente de envío» o, con fecha de envío, «Enviado».
     *
     * @param  list<UploadedFile>  $files
     */
    public function create(User $actor, array $data, array $files = []): RepairComponent
    {
        $this->authorizeManage($actor);
        $companyId = (int) CompanyContext::currentId();
        abort_if($companyId === 0, 404);

        $status = ($data['status'] ?? RepairComponent::PENDING) === RepairComponent::SENT ? RepairComponent::SENT : RepairComponent::PENDING;
        $values = $this->validated($companyId, $data, $status);

        $component = new RepairComponent;
        $component->forceFill(['company_id' => $companyId, 'status' => $status, 'created_by' => $actor->id] + $values);

        DB::transaction(function () use ($component, $files, $actor) {
            $component->save();

            foreach ($files as $file) {
                $this->attach($actor, $component, $file);
            }
        });

        return $component;
    }

    /** Corrige los datos (no el estado: ver changeStatus). */
    public function update(User $actor, RepairComponent $component, array $data): RepairComponent
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $component->company_id);

        return DB::transaction(function () use ($component, $data) {
            $locked = RepairComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $values = $this->validated((int) $locked->company_id, $data + $locked->only(self::EDITABLE), $locked->status);
            $component->forceFill($values)->save();

            return $component;
        });
    }

    /**
     * Cambio de estado: solo a uno permitido desde el actual (lo vuelve a
     * leer bajo bloqueo: dos cambios a la vez no saltean pasos). Con el
     * cambio se pueden cargar las fechas y montos que correspondan.
     */
    public function changeStatus(User $actor, RepairComponent $component, string $to, array $data = []): RepairComponent
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $component->company_id);

        return DB::transaction(function () use ($component, $to, $data) {
            $locked = RepairComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();

            if (! in_array($to, $locked->nextStatuses(), true)) {
                $from = RepairComponent::STATUSES[$locked->status] ?? $locked->status;
                $target = RepairComponent::STATUSES[$to] ?? $to;
                throw ValidationException::withMessages(['status' => "No se puede pasar de «{$from}» a «{$target}»."]);
            }

            $allowed = array_intersect_key($data, array_flip(['sent_at', 'expected_return_at', 'returned_at', 'quote_amount', 'final_cost', 'provider']));
            $values = $this->validated((int) $locked->company_id, $allowed + $locked->only(self::EDITABLE), $to);
            $component->forceFill(['status' => $to] + $values)->save();

            return $component;
        });
    }

    private function validated(int $companyId, array $data, string $status): array
    {
        $errors = [];

        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->find((int) ($data['building_id'] ?? 0));
        if (! $building) {
            $errors['building_id'] = 'Elegí un edificio de la empresa.';
        }

        $elevator = null;
        if (filled($data['elevator_id'] ?? null)) {
            $elevator = Elevator::withoutGlobalScopes()->where('company_id', $companyId)->find((int) $data['elevator_id']);
            if (! $elevator || ! $building || (int) $elevator->building_id !== (int) $building->id) {
                $errors['elevator_id'] = 'Elegí un ascensor de ese edificio.';
            }
        }

        $workOrder = null;
        if (filled($data['work_order_id'] ?? null)) {
            $workOrder = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->find((int) $data['work_order_id']);
            if (! $workOrder || ! $building || (int) $workOrder->building_id !== (int) $building->id) {
                $errors['work_order_id'] = 'Elegí una orden de trabajo de ese edificio.';
            }
        }

        if (! array_key_exists((string) ($data['component_type'] ?? ''), RepairComponent::TYPES)) {
            $errors['component_type'] = 'Elegí el tipo de componente.';
        }

        $failure = Str::limit(trim((string) ($data['failure'] ?? '')), 5000, '');
        if ($failure === '') {
            $errors['failure'] = 'Describí la falla.';
        }

        $follow = null;
        if (filled($data['follow_up_user_id'] ?? null)) {
            $follow = User::withTrashed()->find((int) $data['follow_up_user_id']);
            if (! $follow || ! $this->eligible($follow, $companyId)) {
                $errors['follow_up_user_id'] = 'Elegí una persona de la empresa con acceso a reparaciones.';
            }
        }

        $date = function (string $field) use ($data, &$errors): ?Carbon {
            if (blank($data[$field] ?? null)) {
                return null;
            }
            try {
                return Carbon::parse((string) $data[$field])->startOfDay();
            } catch (\Throwable) {
                $errors[$field] = 'Fecha no válida.';

                return null;
            }
        };
        $sent = $date('sent_at');
        $expected = $date('expected_return_at');
        $returned = $date('returned_at');

        // Ya salió de la empresa (cualquier estado salvo pendiente; cancelado conserva la que tenía).
        if ($status !== RepairComponent::PENDING && $status !== RepairComponent::CANCELLED && ! $sent) {
            $errors['sent_at'] ??= 'Indicá la fecha de envío.';
        }
        if ($status === RepairComponent::PENDING && $sent) {
            $errors['sent_at'] ??= 'Un componente pendiente de envío todavía no tiene fecha de envío.';
        }
        if ($sent?->gt(today())) {
            $errors['sent_at'] ??= 'La fecha de envío no puede ser futura.';
        }
        if ($expected && $sent && $expected->lt($sent)) {
            $errors['expected_return_at'] ??= 'La devolución estimada no puede ser anterior al envío.';
        }
        if ($status === RepairComponent::RECEIVED && ! $returned) {
            $errors['returned_at'] ??= 'Indicá la fecha en que se recibió.';
        }
        if ($status !== RepairComponent::RECEIVED && $returned) {
            $errors['returned_at'] ??= 'La fecha de devolución se carga al recibirlo.';
        }
        if ($returned?->gt(today())) {
            $errors['returned_at'] ??= 'La fecha de devolución no puede ser futura.';
        }
        if ($returned && $sent && $returned->lt($sent)) {
            $errors['returned_at'] ??= 'La devolución no puede ser anterior al envío.';
        }

        $amount = function (string $field) use ($data, &$errors): ?string {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_AMOUNT) {
                $errors[$field] = 'Importe no válido.';

                return null;
            }

            return number_format((float) $value, 2, '.', '');
        };
        $quote = $amount('quote_amount');
        $cost = $amount('final_cost');

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $text = fn (string $field, int $max) => Str::limit(trim((string) ($data[$field] ?? '')), $max, '') ?: null;

        return [
            'building_id' => $building->id,
            'elevator_id' => $elevator?->id,
            'work_order_id' => $workOrder?->id,
            'component_type' => $data['component_type'],
            'description' => $text('description', 160),
            'brand' => $text('brand', 120),
            'model' => $text('model', 120),
            'serial_number' => $text('serial_number', 120),
            'failure' => $failure,
            'sent_at' => $sent?->toDateString(),
            'provider' => $text('provider', 160),
            'follow_up_user_id' => $follow?->id,
            'quote_amount' => $quote,
            'final_cost' => $cost,
            'expected_return_at' => $expected?->toDateString(),
            'returned_at' => $returned?->toDateString(),
            'notes' => $text('notes', 5000),
        ];
    }

    /** Documento o foto (tipo por contenido, nombre aleatorio, disco privado). */
    public function attach(User $actor, RepairComponent $component, UploadedFile $file): RepairComponentFile
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $component->company_id);

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['files' => 'Solo PDF o imágenes (JPG, PNG, WEBP).']);
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['files' => 'Cada archivo puede pesar hasta 20 MB.']);
        }

        $path = 'repairs/'.$component->company_id.'/'.Str::random(40).'.'.self::MIMES[$mime];
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        try {
            $row = new RepairComponentFile;
            $row->forceFill([
                'company_id' => $component->company_id,
                'repair_component_id' => $component->id,
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
                'mime' => $mime,
                'size' => $file->getSize(),
                'uploaded_by' => $actor->id,
            ])->save();
            ActivityLogger::record('repair_component.file_added', $component, ['file' => $row->original_name]);

            return $row;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }
}
