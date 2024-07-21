<?php

use App\Models\ExcludedTag;
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
        Schema::create('excluded_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);
            $table->timestamp('created_at')->useCurrent();
        });

        ExcludedTag::query()->insert([
            ['name' => 'cars'],
            ['name' => 'artwork'],
            ['name' => 'arts'],
            ['name' => 'auto'],
            ['name' => 'train'],
            ['name' => 'corporation'],
            ['name' => 'coach'],
            ['name' => 'mural'],
            ['name' => 'publicart'],
            ['name' => 'transformer'],
            ['name' => 'vehicle'],
            ['name' => 'print'],
            ['name' => 'fursuit'],
            ['name' => 'disneyland'],
            ['name' => 'bird'],
            ['name' => 'museum'],
            ['name' => 'ford'],
            ['name' => 'liquor'],
            ['name' => 'statue'],
            ['name' => 'cabriolet'],
            ['name' => 'city'],
            ['name' => 'sculture'],
            ['name' => 'monkey'],
            ['name' => 'slug'],
            ['name' => 'scout'],
            ['name' => 'ubisoft'],
            ['name' => 'artificial'],
            ['name' => 'sculpture'],
            ['name' => 'tigerlily'],
            ['name' => 'bus'],
            ['name' => 'coin'],
            ['name' => 'painting'],
            ['name' => 'textile'],
            ['name' => 'fly'],
            ['name' => 'drawing'],
            ['name' => 'sea'],
            ['name' => 'football'],
            ['name' => 'boeing'],
            ['name' => 'plane'],
            ['name' => 'turtle'],
            ['name' => 'flies'],
            ['name' => 'robocup'],
            ['name' => 'bee'],
            ['name' => 'moth'],
            ['name' => 'braves'],
            ['name' => 'reed'],
            ['name' => 'frog'],
            ['name' => 'tortoise'],
            ['name' => 'orchid'],
            ['name' => 'shrimp'],
            ['name' => 'basketball'],
            ['name' => 'engine'],
            ['name' => 'referee'],
            ['name' => 'mk4'],
            ['name' => 'thunderbird'],
            ['name' => 'island'],
            ['name' => 'arlington'],
            ['name' => 'spreadwing'],
            ['name' => 'grouper'],
            ['name' => 'ferrari'],
            ['name' => 'submarine'],
            ['name' => 'aircraft'],
            ['name' => 'spider'],
            ['name' => 'rot'],
            ['name' => 'helicopter'],
            ['name' => 'chopper'],
            ['name' => 'milf'],
            ['name' => 'airline'],
            ['name' => 'woman'],
            ['name' => 'mercury'],
            ['name' => 'defcon'],
            ['name' => 'secondlife'],
            ['name' => 'whiptail'],
            ['name' => 'septentrional'],
            ['name' => 'flower'],
            ['name' => 'library'],
            ['name' => 'championship'],
            ['name' => 'faux'],
            ['name' => 'religion'],
            ['name' => 'scan'],
            ['name' => 'sports'],
            ['name' => 'universitario'],
            ['name' => 'ucuenca'],
            ['name' => 'symbol'],
            ['name' => 'campeonato'],
            ['name' => 'stone'],
            ['name' => 'hotel'],
            ['name' => 'leyland'],
            ['name' => 'greatcouncilstatepark'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excluded_tags');
    }
};
