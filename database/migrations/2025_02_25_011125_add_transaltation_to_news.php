<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->boolean('is_translated')->default(false)->after('classification');
            $table->text('analysis')->nullable()->after('is_translated');
            $table->boolean('is_deep')->default(false)->after('analysis');
            $table->boolean('is_deepest')->default(false)->after('is_deep');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn('is_translated');
            $table->dropColumn('analysis');
            $table->dropColumn('is_deep');
            $table->dropColumn('is_deepest');
        });
    }
};
