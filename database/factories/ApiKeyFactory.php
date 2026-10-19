<?php

namespace Aurora\Database\Factories;

use Aurora\Enums\ApiScope;
use Aurora\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'aurora_'.Str::random(40);

        return [
            'name' => fake()->words(2, true),
            'key_hash' => ApiKey::hash($key),
            'hint' => Str::limit($key, 11, ''),
            'scopes' => ApiScope::all(),
        ];
    }
}
