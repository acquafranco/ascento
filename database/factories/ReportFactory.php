<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Report;
use App\Models\ReportPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'company_id' => fn (array $attributes) => Building::withoutGlobalScopes()
                ->find($attributes['building_id'])->company_id,
            'user_id' => fn (array $attributes) => User::factory()->technician()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'elevator_number' => 'Ascensor 1',
            'description' => fake()->sentence(),
            'priority' => 'media',
            'status' => 'pendiente',
        ];
    }

    /**
     * Con una foto (fila en report_photos + archivo en el disco), como la
     * deja la app o la migración de la foto única vieja.
     */
    public function withPhoto(string $disk = 'local', ?string $path = null, bool $legacyColumn = false): static
    {
        return $this->afterCreating(function (Report $report) use ($disk, $path, $legacyColumn) {
            $path ??= 'reports/'.$report->company_id.'/'.Str::random(40).'.jpg';

            Storage::disk($disk)->put($path, UploadedFile::fake()->image('foto.jpg', 40, 30)->getContent());

            ReportPhoto::create(['report_id' => $report->id, 'path' => $path, 'width' => 40, 'height' => 30]);

            if ($legacyColumn) {
                $report->forceFill(['photo' => $path])->saveQuietly();
            }
        });
    }
}
