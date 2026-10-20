<?php

namespace App\Services\Staff;

use App\Enums\PermissionArea;
use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Access\Access;
use App\Support\Auth\UserEmail;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de usuarios administrativos (Etapa 6, PR 2). Único camino para
 * crearlos, editarlos, darles permisos, desactivarlos y reactivarlos.
 *
 * - Solo el dueño de la empresa (role = admin), y solo sobre administrativos
 *   de SU empresa. Un administrativo nunca se gestiona a sí mismo ni a otro.
 * - Límite por plan (Inicial 0, Profesional 1, Empresa y prueba gratis 5)
 *   al crear y al reactivar, verificado acá y en el modelo (ConsumesPlanLimit).
 * - Desactivar no borra nada: soft delete (cierra el acceso y las sesiones;
 *   el historial queda a su nombre). Los permisos se conservan.
 * - Todo queda en el registro de actividad (quién, a quién, cuándo).
 */
class StaffManager
{
    /** Niveles por área en el formulario → claves guardadas. */
    public static function keysFromLevels(array $levels): array
    {
        $keys = [];

        foreach (PermissionArea::cases() as $area) {
            $level = $levels[$area->value] ?? null;

            if ($level === PermissionArea::MANAGE && $area->hasManage()) {
                $keys[] = $area->key(PermissionArea::MANAGE);
            } elseif ($level === PermissionArea::VIEW) {
                $keys[] = $area->key(PermissionArea::VIEW);
            }
        }

        return $keys;
    }

    /** Claves guardadas → nivel por área (para el formulario). */
    public static function levelsFromKeys(array $keys): array
    {
        $levels = [];

        foreach (PermissionArea::cases() as $area) {
            $levels[$area->value] = match (true) {
                $area->hasManage() && in_array($area->key(PermissionArea::MANAGE), $keys, true) => PermissionArea::MANAGE,
                in_array($area->key(PermissionArea::VIEW), $keys, true) => PermissionArea::VIEW,
                default => 'none',
            };
        }

        return $levels;
    }

    /** @param  array{name: string, email: string, phone?: ?string, password: string}  $data */
    public function create(User $owner, array $data, array $levels = []): User
    {
        $company = $this->ownerCompany($owner);

        if (UserEmail::taken($data['email'] ?? null)) {
            throw ValidationException::withMessages(['email' => UserEmail::TAKEN_MESSAGE]);
        }

        return UserEmail::guard(fn () => DB::transaction(function () use ($company, $data, $levels) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            PlanGuard::for($company)->ensureCanAdd(PlanLimit::AdminUsers);

            $user = new User;
            $user->forceFill([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'company_id' => $company->id,
                'role' => User::ROLE_STAFF,
                'job_type' => null,
                'permissions' => self::keysFromLevels($levels),
            ])->save();

            ActivityLogger::record('staff.created', $user, [
                'permissions' => Access::permissionsOf($user),
            ], $user->name);

            return $user;
        }));
    }

    /** Nombre, correo y teléfono (la contraseña la cambia la persona desde su perfil). */
    public function update(User $owner, User $staff, array $data): User
    {
        $this->guardTarget($owner, $staff);
        if (UserEmail::taken($data['email'] ?? null, (int) $staff->id)) {
            throw ValidationException::withMessages(['email' => UserEmail::TAKEN_MESSAGE]);
        }

        $staff->forceFill([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
        ]);

        $changed = array_keys($staff->getDirty());
        UserEmail::guard(fn () => $staff->save());

        if ($changed !== []) {
            ActivityLogger::record('staff.updated', $staff, ['fields' => array_values(array_intersect($changed, ['name', 'email', 'phone']))], $staff->name);
        }

        return $staff;
    }

    /**
     * Permisos (el registro de quién, a quién y cuándo lo deja UserAccessObserver).
     * Solo cambian las áreas que vienen en $levels: las demás se conservan
     * (un formulario que no muestra un área no la borra).
     */
    public function setPermissions(User $owner, User $staff, array $levels): User
    {
        $this->guardTarget($owner, $staff);

        $submitted = array_values(array_filter(PermissionArea::cases(), fn (PermissionArea $area) => array_key_exists($area->value, $levels)));
        $kept = array_values(array_filter(Access::permissionsOf($staff), fn (string $key) => ! in_array(explode('.', $key)[0], array_map(fn ($a) => $a->value, $submitted), true)));

        $staff->forceFill(['permissions' => array_values(array_unique([...$kept, ...self::keysFromLevels($levels)]))])->save();

        return $staff->fresh();
    }

    public function deactivate(User $owner, User $staff): void
    {
        $this->guardTarget($owner, $staff);

        DB::transaction(function () use ($staff) {
            $staff->delete();
            DB::table('sessions')->where('user_id', $staff->id)->delete();
        });

        ActivityLogger::record('staff.deactivated', $staff, [], $staff->name);
    }

    public function reactivate(User $owner, User $staff): void
    {
        $this->guardTarget($owner, $staff, allowTrashed: true);

        DB::transaction(function () use ($staff) {
            Company::whereKey($staff->company_id)->lockForUpdate()->first();
            $staff->restore(); // ConsumesPlanLimit verifica el cupo del plan
        });

        ActivityLogger::record('staff.reactivated', $staff, [], $staff->name);
    }

    private function ownerCompany(User $owner): Company
    {
        abort_unless(Access::isOwner($owner), 403);

        return Company::findOrFail($owner->company_id);
    }

    private function guardTarget(User $owner, User $staff, bool $allowTrashed = false): void
    {
        $this->ownerCompany($owner);

        // Otro rol u otra empresa: como si no existiera.
        abort_unless($staff->role === User::ROLE_STAFF && (int) $staff->company_id === (int) $owner->company_id, 404);

        if (! $allowTrashed && $staff->trashed()) {
            throw ValidationException::withMessages(['staff' => 'Este administrativo está desactivado: reactivalo primero.']);
        }
    }
}
