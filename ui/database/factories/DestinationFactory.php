<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DestinationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name'  => $this->faker->unique()->word,
            'host'  => 'dev.manticoresearch.com:22',
            'topic' => 'out.{username}',
            'group' => 'MS_' . $this->faker->word,
        ];
    }
}
