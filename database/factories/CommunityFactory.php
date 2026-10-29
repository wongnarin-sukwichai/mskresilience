<?php

namespace Database\Factories;

use App\Models\Community;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'R'.fake()->unique()->numerify('9##'),
            'name' => 'ทดสอบ '.fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
