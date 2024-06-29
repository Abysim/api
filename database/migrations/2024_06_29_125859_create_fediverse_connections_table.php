<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fediverse_connections', function (Blueprint $table) {
            $table->id();
            $table->integer('account_id');
            $table->string('url');
            $table->string('handle');
            $table->string('client_id');
            $table->string('client_secret');
            $table->string('token');
            $table->string('cat');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fediverse_connections');
    }
};
