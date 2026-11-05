<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => 'Stadtplatz '.fake()->unique()->numberBetween(1, 9999),
            'description' => 'Blick auf den Stadtplatz.',
            'reference_path' => 'references/test.jpg',
            'output_width' => 1600,
            'output_height' => 1200,
            'min_inliers' => 40,
            'is_active' => true,
        ];
    }

    public function withoutReference(): static
    {
        return $this->state(['reference_path' => null]);
    }
}
