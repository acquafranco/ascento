<?php

use App\Support\Auth\UserEmailConflicts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Un correo = una sola cuenta, también en la base: users.email_key es el
 * correo sin espacios alrededor y en minúsculas (columna generada, no se
 * escribe) con índice único. Así «Ana@x.com», «ana@x.com» y « ana@x.com »
 * no pueden ser dos cuentas, aunque dos pedidos lleguen al mismo tiempo o
 * un proceso escriba sin pasar por los formularios.
 *
 * No modifica ni borra datos. Si ya hubiera correos repetidos con otra
 * forma, NO se fuerza nada: se crea un índice común, se avisa en el log y
 * `php artisan users:email-conflicts` los lista para resolverlos a mano;
 * después, `php artisan users:email-conflicts --enforce` crea el índice único.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_key')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email_key')->nullable()->virtualAs('lower(trim(email))')->after('email');
            });
        }

        $conflicts = UserEmailConflicts::find();
        if ($conflicts === []) {
            UserEmailConflicts::enforce();

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->index('email_key', UserEmailConflicts::PLAIN_INDEX);
        });
        Log::warning('Correos de usuario repetidos: no se creó el índice único de users.email_key. Revisá con `php artisan users:email-conflicts`.', ['groups' => count($conflicts)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([UserEmailConflicts::UNIQUE_INDEX, UserEmailConflicts::PLAIN_INDEX] as $index) {
                if (Schema::hasIndex('users', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn('users', 'email_key')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_key'));
        }
    }
};
