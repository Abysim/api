<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            $table->string('external_id', 64);
            $table->date('date');
            $table->string('author', 128);
            $table->string('title');
            $table->text('content');
            $table->json('tags')->nullable();
            $table->string('link', 1024);
            $table->string('source', 32);
            $table->string('language', 5)->nullable();
            $table->string('media', 1024)->nullable();
            $table->string('filename')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->json('classification')->nullable();
            $table->string('publish_title')->nullable();
            $table->text('publish_content')->nullable();
            $table->string('publish_tags')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
