<?php

namespace Aurora\Database\Factories;

use Aurora\Models\AssetTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetTag>
 */
class AssetTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => fake()->randomElement(AssetTag::COLORS),
        ];
    }
}
