<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->json('content_hashes')->nullable()->after('analysis_count');
            $table->text('previous_analysis')->nullable()->after('content_hashes');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn(['content_hashes', 'previous_analysis']);
        });
    }
};
