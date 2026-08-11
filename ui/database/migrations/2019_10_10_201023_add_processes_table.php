<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddProcessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->char('label', 6)->unique();
            $table->string('name');
            $table->bigInteger('user_id', 0, true)->nullable();
            $table->integer('source_id', 0, true)->nullable();
            $table->integer('destination_id', 0, true)->nullable();
            $table->text('values');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null')->onUpdate('set null');
            $table->foreign('source_id')->references('id')->on('sources')->onDelete('set null')->onUpdate('set null');
            $table->foreign('destination_id')->references('id')->on('destinations')->onDelete('set null')->onUpdate('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('processes');
    }
}
