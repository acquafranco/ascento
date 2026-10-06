<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuildingVisit>
 */
class BuildingVisitFactory extends Factory
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
            'source' => 'building',
            'visit_type' => 'fixed',
            'assignment_type' => 'maintenance',
            'status' => 'done',
            'month' => now()->month,
            'year' => now()->year,
            'visited_at' => now(),
        ];
    }
}
