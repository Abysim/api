<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('post_id', 1024)->change();
            $table->string('parent_post_id', 1024)->change();
            $table->string('root_post_id', 1024)->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('post_id')->change();
            $table->string('parent_post_id')->change();
            $table->string('root_post_id')->change();
        });
    }
};
