<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'name' => fake()->streetName(),
            'address' => fake()->buildingNumber(),
            'elevator_count' => 2,
            'freight_elevator_count' => 0,
            'is_active' => true,
        ];
    }
}
