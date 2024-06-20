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
        Schema::table('bluesky_connections', function (Blueprint $table) {
            $table->string('jwt', 1024)->change();
            $table->string('refresh', 1024)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bluesky_connections', function (Blueprint $table) {
            $table->string('jwt')->change();
            $table->string('refresh')->nullable()->change();
        });
    }
};
