<?php

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if ( ! empty(getenv('SUPERADMIN_EMAIL'))) {
            $email = getenv('SUPERADMIN_EMAIL');
        } else {
            $email = 'admin@example.com';
        }


        if ( ! empty(getenv('SUPERADMIN_PASSWORD'))) {
            $pass = getenv('SUPERADMIN_PASSWORD');
        } else {
            $pass = 'changeme';
        }

        $admin           = new User();
        $admin->email    = $email;
        $admin->name     = 'Admin';
        $admin->role_id  = 1;
        $admin->api_token = Str::random();
        $admin->password = bcrypt($pass);
        $admin->save();
    }
}
