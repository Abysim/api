<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flickr_photos', function (Blueprint $table) {
            $table->bigInteger('perceptual_hash')->nullable()->after('classification');
            $table->index('perceptual_hash');
        });
    }

    public function down(): void
    {
        Schema::table('flickr_photos', function (Blueprint $table) {
            $table->dropIndex(['perceptual_hash']);
            $table->dropColumn('perceptual_hash');
        });
    }
};
