<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->renameColumn('fetch_scrapedo', 'fetch_diffbot');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->renameColumn('fetch_diffbot', 'fetch_scrapedo');
        });
    }
};
