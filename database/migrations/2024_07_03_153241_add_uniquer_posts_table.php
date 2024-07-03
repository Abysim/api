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
        Schema::table('posts', function (Blueprint $table) {
            $table->unique(['connection', 'connection_id', 'post_id']);
        });

        Schema::table('post_forwards', function (Blueprint $table) {
            $table->unique(['from_id', 'to_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['connection', 'connection_id', 'post_id']);
        });

        Schema::table('post_forwards', function (Blueprint $table) {
            $table->dropUnique(['from_id', 'to_id']);
        });
    }
};
