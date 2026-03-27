<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->unsignedInteger('fetch_vps_scraper')->default(0)->after('fetch_diffbot');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropColumn('fetch_vps_scraper');
        });
    }
};
