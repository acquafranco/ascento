<?php

namespace App\Filament\Resources\Reports\Pages;

use Illuminate\Validation\ValidationException;

class ReportPhotoForm
{
    /**
     * Los errores de ReportPhotoService vienen como "photos": en el panel se
     * muestran debajo del campo de fotos.
     */
    public static function withPhotoErrors(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.new_photos' => $e->errors()['photos'] ?? collect($e->errors())->flatten()->all()]);
        }
    }
}
