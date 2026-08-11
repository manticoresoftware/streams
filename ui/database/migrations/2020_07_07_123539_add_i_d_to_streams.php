<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIDToStreams extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('label');
        });

        Schema::table('streams', function (Blueprint $table) {
            $table->bigIncrements('id')->first();
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn('id');
        });

        Schema::table('streams', function (Blueprint $table) {
            $table->char('label', 6)->unique()->primary();
        });
    }
}
