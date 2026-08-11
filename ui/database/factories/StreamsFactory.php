<?php

namespace Database\Factories;

use App\Models\Processes;
use App\Models\User;
use DB;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class StreamsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        $fakeProcess = Processes::factory()->create();
        $fakeUser    = User::factory()->create();

        $statement = DB::select("SHOW TABLE STATUS LIKE 'streams'");
        $streamId  = $statement[0]->Auto_increment;

        $fakeUser->process = $streamId;
        $fakeUser->save();

        return [
            'id'         => $fakeUser->process,
            'user_id'    => $fakeUser->id,
            'process_id' => $fakeProcess->id,
        ];

    }
}
