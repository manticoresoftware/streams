<?php

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $role_admin              = new Role();
        $role_admin->name        = 'admin';
        $role_admin->description = 'A Admin';
        $role_admin->save();


        $role_manager              = new Role();
        $role_manager->name        = 'manager';
        $role_manager->description = 'A Manager User';
        $role_manager->save();
    }
}
