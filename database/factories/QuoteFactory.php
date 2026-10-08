<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'company_id' => fn (array $attributes) => Building::withoutGlobalScopes()
                ->find($attributes['building_id'])->company_id,
            'created_by' => fn (array $attributes) => User::factory()->admin()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'client_id' => fn (array $attributes) => Building::withoutGlobalScopes()
                ->find($attributes['building_id'])->client_id,
            'title' => fake()->sentence(3),
            'amount' => 1000,
            'status' => 'draft',
        ];
    }
}
