<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flickr_photos', function (Blueprint $table) {
            $table->string('thumbnail_url', 1024)->nullable()->after('source_url');
            $table->unsignedSmallInteger('thumbnail_width')->nullable()->after('thumbnail_url');
            $table->unsignedSmallInteger('thumbnail_height')->nullable()->after('thumbnail_width');
        });
    }

    public function down(): void
    {
        Schema::table('flickr_photos', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_url', 'thumbnail_width', 'thumbnail_height']);
        });
    }
};
