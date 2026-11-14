<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** For Review: when a goal was achieved, and each time a block was moved or resized on the Planner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dateTime('achieved_at')->nullable()->after('progress');
        });
        DB::table('goals')->where('status', 'achieved')->update(['achieved_at' => DB::raw('updated_at')]);
        Schema::create('planner_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('planner_block_id');
            $table->dateTime('moved_at');
            $table->index(['user_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planner_moves');
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('achieved_at');
        });
    }
};
