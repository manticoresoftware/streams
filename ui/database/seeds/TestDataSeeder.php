<?php

use App\Models\Destination;
use App\Models\Processes;
use App\Models\Source;
use App\Models\Streams;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (in_array(App::environment(), ['testing', 'dev'])) {
            $user           = new User();
            $user->email    = 'manager@example.com';
            $user->name     = 'Manager';
            $user->role_id  = 2;
            $user->password = bcrypt('aqaqaq');
            $user->process = 1;
            $user->save();

            Source::factory()->create();
            Destination::factory()->create();
            $process = Processes::factory()->create();

            $stream             = new Streams();
            $stream->id = 1;
            $stream->user_id    = $user->id;
            $stream->process_id = $process->id;
            $stream->save();
        }

        if(App::environment() === 'cluster_testing'){
            $user           = new User();
            $user->email    = 'manager@example.com';
            $user->name     = 'Manager';
            $user->role_id  = 2;
            $user->password = bcrypt('aqaqaq');
            $user->api_token = Str::random();
            $user->process = null;
            $user->save();
        }
    }
}
