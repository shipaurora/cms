<?php

namespace Aurora\Database\Factories;

use Aurora\Models\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locale>
 */
class LocaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->randomElement(array_keys(Locale::KNOWN));

        return [
            'code' => $code,
            'name' => Locale::KNOWN[$code],
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
