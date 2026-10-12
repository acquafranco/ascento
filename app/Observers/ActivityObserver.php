<?php

namespace App\Observers;

use App\Services\Activity\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Altas, cambios relevantes, bajas y reactivaciones de los modelos de
 * ActivityLogger::FIELDS hechas desde el panel. «client.updated» solo si
 * cambió un campo permitido.
 */
class ActivityObserver
{
    public function created(Model $model): void
    {
        ActivityLogger::record($this->key($model).'.created', $model, ActivityLogger::snapshotOf($model));
    }

    public function updated(Model $model): void
    {
        if ($changes = ActivityLogger::changesOf($model)) {
            ActivityLogger::record($this->key($model).'.updated', $model, $changes);
        }
    }

    public function deleted(Model $model): void
    {
        $forced = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

        ActivityLogger::record($this->key($model).($forced ? '.force_deleted' : '.deleted'), $model, ActivityLogger::snapshotOf($model));
    }

    public function restored(Model $model): void
    {
        ActivityLogger::record($this->key($model).'.restored', $model);
    }

    private function key(Model $model): string
    {
        return Str::snake(class_basename($model));
    }
}
