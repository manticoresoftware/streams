<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;


class VariableFactory extends Factory
{
    public function definition(): array
    {
        $word = $this->faker->words(3, true);
        return [
            'name' => 'variable_'.bin2hex(random_bytes(12)),
            'text' => $word
        ];
    }
}
