<?php

namespace Aurora\Database\Factories;

use Aurora\Models\NavigationLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NavigationLocation>
 */
class NavigationLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::ucfirst(fake()->unique()->words(2, true));

        return [
            'handle' => Str::slug($name),
            'name' => $name,
        ];
    }
}
