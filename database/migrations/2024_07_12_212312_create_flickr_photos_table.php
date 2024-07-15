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
        Schema::create('flickr_photos', function (Blueprint $table) {
            $table->id();
            $table->string('secret', 16);
            $table->string('owner', 16);
            $table->string('owner_username', 64)->nullable();
            $table->string('owner_realname', 128)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('url')->nullable();
            $table->string('filename', 64)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->json('classification')->nullable();
            $table->string('publish_title')->nullable();
            $table->string('publish_tags')->nullable();
            $table->integer('message_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flickr_photos');
    }
};
