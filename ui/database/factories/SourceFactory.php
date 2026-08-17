<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'name'  => $this->faker->unique()->word,
            'host'  => 'dev.manticoresearch.com:22',
            'topic' => 'my-docs',
            'group' => 'MS_' . $this->faker->word,
        ];
    }
}
