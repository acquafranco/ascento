<?php

namespace App\Filament\Resources\OnCall\Pages;

use Filament\Notifications\Notification;

/** Resultado de recalcular una rotación, en palabras (y si quedaron turnos sin técnico). */
final class OnCallRotationFeedback
{
    public static function send(array $result): void
    {
        $body = "{$result['created']} turnos nuevos".($result['cancelled'] ? ", {$result['cancelled']} recalculados" : '').'.';

        if ($result['uncovered']) {
            Notification::make()->title('Rotación actualizada, con turnos sin técnico')
                ->body($body.' '.count($result['uncovered']).' turnos no tienen técnico disponible (por exclusiones o falta de participantes): aparecen en «Para revisar», en Guardias.')
                ->warning()->persistent()->send();

            return;
        }

        Notification::make()->title('Rotación actualizada')->body($body)->success()->send();
    }
}
