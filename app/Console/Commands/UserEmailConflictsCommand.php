<?php

namespace App\Console\Commands;

use App\Support\Auth\UserEmailConflicts;
use Illuminate\Console\Command;

/**
 * Lista las cuentas que comparten correo (sin mostrarlo completo) y, con
 * --enforce, crea el índice único cuando ya no queda ninguna. Nunca borra,
 * fusiona ni modifica cuentas: eso se decide a mano, caso por caso.
 */
class UserEmailConflictsCommand extends Command
{
    protected $signature = 'users:email-conflicts {--enforce : Crear el índice único de correos si ya no hay repetidos}';

    protected $description = 'Revisa correos de usuario repetidos (mayúsculas o espacios) sin modificar ninguna cuenta';

    public function handle(): int
    {
        $conflicts = UserEmailConflicts::find();

        if ($conflicts === []) {
            $this->info('No hay correos repetidos.');
        } else {
            $this->warn(count($conflicts).' '.(count($conflicts) === 1 ? 'correo está' : 'correos están').' en más de una cuenta:');
            foreach ($conflicts as $group) {
                $this->line(UserEmailConflicts::mask($group['key']));
                $this->table(['Usuario', 'Rol', 'Empresa', 'Cliente', 'Desactivada', 'SuperAdmin'], array_map(fn ($u) => [
                    $u['id'], $u['role'] ?? '—', $u['company_id'] ?? '—', $u['client_id'] ?? '—', $u['deactivated'] ? 'sí' : 'no', $u['super_admin'] ? 'sí' : 'no',
                ], $group['users']));
            }
            $this->line('Para cada caso, decidí con las personas involucradas cuál cuenta conserva el correo y cambiá el de las demás desde el panel. Ascento no los resuelve solo.');
        }

        if ($this->option('enforce')) {
            if (UserEmailConflicts::enforce()) {
                $this->info('Índice único de correos activo.');
            } else {
                $this->error('No se creó el índice único: primero resolvé los correos repetidos.');

                return self::FAILURE;
            }
        } else {
            $this->line(UserEmailConflicts::enforced() ? 'Índice único de correos: activo.' : 'Índice único de correos: pendiente (usá --enforce cuando no haya repetidos).');
        }

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }
}
