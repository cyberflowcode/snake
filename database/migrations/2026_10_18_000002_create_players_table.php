<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlayersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->string('nickname', 32);
            $table->string('token_hash', 64)->index();
            $table->string('color', 20)->default('cyan');
            $table->boolean('is_host')->default(false);
            $table->boolean('is_ready')->default(true);
            $table->boolean('is_alive')->default(true);
            $table->integer('score')->default(0);
            $table->integer('length')->default(10);
            $table->unsignedTinyInteger('rank')->nullable();
            $table->timestamp('last_seen_at')->nullable();
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
        Schema::dropIfExists('players');
    }
}
