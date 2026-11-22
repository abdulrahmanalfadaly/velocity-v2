<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How see-through the task cards are on a wallpaper (Settings → Appearance → Task cards):
 * card_clear in percent (6 = 94% solid), card_blur in pixels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_clear')->default(6);
            $table->unsignedTinyInteger('card_blur')->default(8);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['card_clear', 'card_blur']));
    }
};
