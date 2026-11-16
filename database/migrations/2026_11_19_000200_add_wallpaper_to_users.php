<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The wallpaper behind the pages (Settings → Appearance), and the user's own picture if they uploaded one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wallpaper', 20)->nullable();
            $table->string('wallpaper_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['wallpaper', 'wallpaper_path']));
    }
};
