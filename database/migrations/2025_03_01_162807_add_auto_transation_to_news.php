<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('is_translated');
            $table->tinyInteger('analysis_count')->unsigned()->default(0)->after('analysis');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn('is_auto');
            $table->dropColumn('analysis_count');
        });
    }
};
