<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;


class VariableFactory extends Factory
{
    public function definition(): array
    {
        $word = $this->faker->words(3, true);
        return [
            'name' => $this->faker->unique()->word,
            'text' => $word
        ];
    }
}
