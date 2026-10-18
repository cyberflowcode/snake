<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoomsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 100)->nullable();
            $table->string('status', 20)->default('waiting'); // waiting, countdown, in_progress, finished
            $table->unsignedTinyInteger('max_players')->default(8);
            $table->unsignedTinyInteger('tick_rate')->default(10);
            $table->unsignedInteger('width')->default(2400);
            $table->unsignedInteger('height')->default(2400);
            $table->unsignedBigInteger('host_player_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();
            $table->mediumText('game_state')->nullable();
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
        Schema::dropIfExists('rooms');
    }
}
