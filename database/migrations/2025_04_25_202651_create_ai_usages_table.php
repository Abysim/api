<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->integer('total_tokens')->default(0)->unsigned();
            $table->timestamps();
        });

        Schema::table('news', function (Blueprint $table) {
            $table->integer('max_tokens')->default(0)->unsigned()->after('is_deepest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');

        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn('max_tokens');
        });
    }
};
