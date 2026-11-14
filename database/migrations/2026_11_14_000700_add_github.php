<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub: the user's token (encrypted), a project's repository, and the issues
 * and pull requests linked to tasks (what was last read from GitHub, kept so a
 * page never waits on GitHub).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('github_token')->nullable();
            $table->string('github_login', 100)->nullable();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('github_repo', 140)->nullable();
        });
        Schema::create('github_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('repo', 140);
            $table->unsignedInteger('number');
            $table->string('kind', 8);   // issue | pull
            $table->string('title', 300);
            $table->string('state', 10); // open | closed | merged | draft
            $table->string('url', 300);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'repo', 'number']);
            $table->index(['user_id', 'repo', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_links');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('github_repo'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['github_token', 'github_login']));
    }
};
