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
        Schema::create('forwards', function (Blueprint $table) {
            $table->id();
            $table->string('from_connection');
            $table->bigInteger('from_id');
            $table->string('to_connection');
            $table->bigInteger('to_id')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forwards');
    }
};
