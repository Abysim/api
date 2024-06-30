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
        Schema::table('fediverse_connections', function (Blueprint $table) {
            $table->bigInteger('account_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fediverse_connections', function (Blueprint $table) {
            $table->integer('account_id')->change();
        });
    }
};
