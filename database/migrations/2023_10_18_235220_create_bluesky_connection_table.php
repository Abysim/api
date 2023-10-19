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
        Schema::create('bluesky_connection', function (Blueprint $table) {
            $table->id();
            $table->string('did')->unique();
            $table->string('handle');
            $table->string('secret');
            $table->string('password');
            $table->string('jwt');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bluesky_connection');
    }
};
