<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Report;
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
}
