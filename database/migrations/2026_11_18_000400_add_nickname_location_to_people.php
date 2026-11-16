<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* People: a nickname (search finds it) and where they are. Contacts can now be a username ("@sara", label: Instagram). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('name');
            $table->string('location', 120)->nullable()->after('organisation');
        });
    }

    public function down(): void
    {
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn(['nickname', 'location']));
    }
};
