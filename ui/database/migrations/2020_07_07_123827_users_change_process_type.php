<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UsersChangeProcessType extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::query("ALTER TABLE `users` CHANGE `process` `process` BIGINT(20) NULL DEFAULT NULL");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::query("ALTER TABLE `users` CHANGE `process` `process` char(6) NULL DEFAULT NULL");
    }
}
