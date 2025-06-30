<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->nullableMorphs('snapshotable');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('snapshots');
    }
};
