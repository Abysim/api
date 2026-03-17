<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('fetch_raw_http')->default(0);
            $table->unsignedInteger('fetch_jina')->default(0);
            $table->unsignedInteger('fetch_scrapedo')->default(0);
            $table->unsignedInteger('fetch_scraperapi')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
