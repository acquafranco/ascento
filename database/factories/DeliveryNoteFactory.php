<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNote>
 */
class DeliveryNoteFactory extends Factory
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
            'assignment_type' => 'maintenance',
            'description' => fake()->sentence(),
            'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0,
            'performed' => true,
            'month' => now()->month,
            'year' => now()->year,
            'signature_name' => fake()->name(),
        ];
    }
}
