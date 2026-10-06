<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'company_id' => fn (array $attributes) => Building::withoutGlobalScopes()
                ->find($attributes['building_id'])->company_id,
            'type' => 'claim',
            'status' => 'pending',
            'priority' => 'medium',
            'unit' => 'Ascensor 1',
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }
}
