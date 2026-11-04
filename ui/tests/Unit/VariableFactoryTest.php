<?php

namespace Tests\Unit;

use App\Models\Variable;
use Faker\Generator;
use Tests\TestCase;

/** @group application */
class VariableFactoryTest extends TestCase
{
    public function testVariableNameDoesNotRepeatAfterFakerReseed(): void
    {
        $faker = app(Generator::class);

        try {
            $faker->unique(true);
            $faker->seed(302);
            $first = Variable::factory()->make()->name;

            $faker->unique(true);
            $faker->seed(302);
            $second = Variable::factory()->make()->name;

            self::assertNotSame($first, $second);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $first);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $second);
        } finally {
            $faker->unique(true);
            $faker->seed(random_int(0, PHP_INT_MAX));
        }
    }
}
