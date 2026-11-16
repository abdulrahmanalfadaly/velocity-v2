<?php

use App\Http\Controllers\Api\GitHubApiController;
use App\Http\Controllers\Api\HabitApiController;
use App\Http\Controllers\Api\NoteApiController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\PersonApiController;
use App\Http\Controllers\Api\PlannerApiController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ProjectMoreApiController;
use App\Http\Controllers\Api\TaskApiController;
use App\Http\Controllers\Api\TaskMoreApiController;
use Illuminate\Support\Facades\Route;

/*
 * The API for Mark: /api/v1/…, an access key in "Authorization: Bearer …".
 * Each route names the access it needs (Settings → Integrations). See docs/api.md.
 */
Route::prefix('v1')->name('api.')->group(function () {
    Route::get('health', [OverviewController::class, 'health'])->name('health');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [OverviewController::class, 'me'])->name('me');
        Route::get('today', [OverviewController::class, 'overview'])->name('today');
        Route::get('search', [OverviewController::class, 'search'])->name('search');

        Route::middleware('scope:tasks:read')->group(function () {
            Route::get('tasks', [TaskApiController::class, 'index'])->name('tasks.index');
            Route::get('tasks/{task}', [TaskApiController::class, 'show'])->whereNumber('task')->name('tasks.show');
            Route::post('tasks/preview', [TaskApiController::class, 'preview'])->name('tasks.preview');
            Route::get('focus', [TaskApiController::class, 'focus'])->name('focus.show');
            Route::get('focus/history', [TaskMoreApiController::class, 'history'])->name('focus.history');
            Route::get('tasks/next', [TaskMoreApiController::class, 'next'])->name('tasks.next');
            Route::get('tasks/{task}/reminders', [TaskMoreApiController::class, 'reminders'])->whereNumber('task')->name('reminders.index');
            Route::get('templates', [TaskMoreApiController::class, 'templates'])->name('templates.index');
        });
        Route::middleware('scope:tasks:write')->group(function () {
            Route::post('tasks', [TaskApiController::class, 'store'])->name('tasks.store');
            Route::patch('tasks/{task}', [TaskApiController::class, 'update'])->whereNumber('task')->name('tasks.update');
            Route::post('tasks/{task}/complete', [TaskApiController::class, 'complete'])->whereNumber('task')->name('tasks.complete');
            Route::post('tasks/{task}/rank', [TaskApiController::class, 'rank'])->whereNumber('task')->name('tasks.rank');
            Route::post('tasks/{task}/reopen', [TaskApiController::class, 'reopen'])->whereNumber('task')->name('tasks.reopen');
            Route::delete('tasks/{task}', [TaskApiController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
            Route::post('tasks/{id}/restore', [TaskApiController::class, 'restore'])->whereNumber('id')->name('tasks.restore');
            Route::post('tasks/{task}/steps', [TaskApiController::class, 'addStep'])->whereNumber('task')->name('steps.store');
            Route::patch('steps/{step}', [TaskApiController::class, 'updateStep'])->whereNumber('step')->name('steps.update');
            Route::post('focus', [TaskApiController::class, 'startFocus'])->name('focus.start');
            Route::post('focus/stop', [TaskApiController::class, 'stopFocus'])->name('focus.stop');
            Route::post('focus/pause', [TaskMoreApiController::class, 'pauseFocus'])->name('focus.pause');
            Route::post('focus/resume', [TaskMoreApiController::class, 'resumeFocus'])->name('focus.resume');
            Route::post('focus/next', [TaskMoreApiController::class, 'nextRound'])->name('focus.next');
            Route::post('focus/end', [TaskMoreApiController::class, 'endPomodoro'])->name('focus.end');
            Route::patch('focus/{session}', [TaskMoreApiController::class, 'updateSession'])->whereNumber('session')->name('focus.update');
            Route::post('tasks/{task}/cancel', [TaskMoreApiController::class, 'cancel'])->whereNumber('task')->name('tasks.cancel');
            Route::post('tasks/{task}/archive', [TaskMoreApiController::class, 'archive'])->whereNumber('task')->name('tasks.archive');
            Route::post('tasks/{task}/unarchive', [TaskMoreApiController::class, 'unarchive'])->whereNumber('task')->name('tasks.unarchive');
            Route::post('tasks/bulk', [TaskMoreApiController::class, 'bulk'])->name('tasks.bulk');
            Route::post('tasks/{task}/reminders', [TaskMoreApiController::class, 'addReminder'])->whereNumber('task')->name('reminders.store');
            Route::delete('reminders/{reminder}', [TaskMoreApiController::class, 'removeReminder'])->whereNumber('reminder')->name('reminders.destroy');
            Route::post('reminders/{reminder}/snooze', [TaskMoreApiController::class, 'snoozeReminder'])->whereNumber('reminder')->name('reminders.snooze');
            Route::post('templates/{template}/use', [TaskMoreApiController::class, 'useTemplate'])->whereNumber('template')->name('templates.use');
            Route::post('tasks/{task}/subtasks', [TaskMoreApiController::class, 'subtask'])->whereNumber('task')->name('tasks.subtask');
            Route::post('tasks/{task}/blocks', [TaskMoreApiController::class, 'blocks'])->whereNumber('task')->name('tasks.blocks');
        });

        Route::middleware('scope:projects:read')->group(function () {
            Route::get('projects', [ProjectApiController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectApiController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::get('goals', [ProjectApiController::class, 'goals'])->name('goals.index');
            Route::get('projects/{project}/tasks', [ProjectMoreApiController::class, 'tasks'])->whereNumber('project')->name('projects.tasks');
            Route::get('areas', [ProjectMoreApiController::class, 'areas'])->name('areas.index');
        });
        Route::middleware('scope:projects:write')->group(function () {
            Route::post('projects', [ProjectApiController::class, 'store'])->name('projects.store');
            Route::patch('projects/{project}', [ProjectApiController::class, 'update'])->whereNumber('project')->name('projects.update');
            Route::patch('milestones/{milestone}', [ProjectApiController::class, 'updateMilestone'])->whereNumber('milestone')->name('milestones.update');
            Route::post('goals', [ProjectApiController::class, 'storeGoal'])->name('goals.store');
            Route::patch('goals/{goal}', [ProjectApiController::class, 'updateGoal'])->whereNumber('goal')->name('goals.update');
            Route::post('projects/{project}/status', [ProjectMoreApiController::class, 'status'])->whereNumber('project')->name('projects.status');
            Route::post('projects/{project}/complete', [ProjectMoreApiController::class, 'complete'])->whereNumber('project')->name('projects.complete');
            Route::post('projects/{project}/reopen', [ProjectMoreApiController::class, 'reopen'])->whereNumber('project')->name('projects.reopen');
            Route::post('areas', [ProjectMoreApiController::class, 'storeArea'])->name('areas.store');
            Route::patch('areas/{area}', [ProjectMoreApiController::class, 'updateArea'])->whereNumber('area')->name('areas.update');
            Route::delete('projects/{project}', [ProjectMoreApiController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');
            Route::post('projects/{id}/restore', [ProjectMoreApiController::class, 'restore'])->whereNumber('id')->name('projects.restore');
            Route::delete('goals/{goal}', [ProjectMoreApiController::class, 'destroyGoal'])->whereNumber('goal')->name('goals.destroy');
            Route::post('goals/{id}/restore', [ProjectMoreApiController::class, 'restoreGoal'])->whereNumber('id')->name('goals.restore');
        });

        Route::middleware('scope:planner:read')->group(function () {
            Route::get('planner', [PlannerApiController::class, 'index'])->name('planner.index');
            Route::get('planner/free', [PlannerApiController::class, 'free'])->name('planner.free');
        });
        Route::middleware('scope:planner:write')->group(function () {
            Route::post('planner/plan', [PlannerApiController::class, 'plan'])->name('planner.plan');
            Route::post('events', [PlannerApiController::class, 'storeEvent'])->name('events.store');
            Route::patch('events/{event}', [PlannerApiController::class, 'updateEvent'])->whereNumber('event')->name('events.update');
            Route::delete('events/{event}', [PlannerApiController::class, 'destroyEvent'])->whereNumber('event')->name('events.destroy');
            Route::patch('events/{event}/move', [PlannerApiController::class, 'moveEvent'])->whereNumber('event')->name('events.move');
            Route::post('events/{event}/cancel', [PlannerApiController::class, 'cancelEvent'])->whereNumber('event')->name('events.cancel');
            Route::post('planner/blocks', [PlannerApiController::class, 'storeBlock'])->name('blocks.store');
            Route::patch('planner/blocks/{block}', [PlannerApiController::class, 'updateBlock'])->whereNumber('block')->name('blocks.update');
            Route::delete('planner/blocks/{block}', [PlannerApiController::class, 'destroyBlock'])->whereNumber('block')->name('blocks.destroy');
        });

        Route::middleware('scope:notes:read')->group(function () {
            Route::get('notes', [NoteApiController::class, 'index'])->name('notes.index');
            Route::get('notes/{note}', [NoteApiController::class, 'show'])->whereNumber('note')->name('notes.show');
            Route::get('journal', [NoteApiController::class, 'journal'])->name('journal.show');
        });
        Route::middleware('scope:notes:write')->group(function () {
            Route::post('notes', [NoteApiController::class, 'store'])->name('notes.store');
            Route::patch('notes/{note}', [NoteApiController::class, 'update'])->whereNumber('note')->name('notes.update');
            Route::post('journal', [NoteApiController::class, 'writeJournal'])->name('journal.write');
            Route::post('notes/{note}/links', [NoteApiController::class, 'link'])->whereNumber('note')->name('notes.links.store');
            Route::delete('notes/{note}/links/{link}', [NoteApiController::class, 'unlink'])->whereNumber(['note', 'link'])->name('notes.links.destroy');
        });

        Route::middleware('scope:habits:read')->group(function () {
            Route::get('habits', [HabitApiController::class, 'index'])->name('habits.index');
            Route::get('habits/{habit}', [HabitApiController::class, 'show'])->whereNumber('habit')->name('habits.show');
        });
        Route::middleware('scope:habits:write')->group(function () {
            Route::post('habits', [HabitApiController::class, 'store'])->name('habits.store');
            Route::post('habits/{habit}/check', [HabitApiController::class, 'check'])->whereNumber('habit')->name('habits.check');
            Route::post('habits/{habit}/skip', [HabitApiController::class, 'skip'])->whereNumber('habit')->name('habits.skip');
            Route::patch('habits/{habit}', [HabitApiController::class, 'update'])->whereNumber('habit')->name('habits.update');
            Route::put('habits/{habit}/day', [HabitApiController::class, 'setDay'])->whereNumber('habit')->name('habits.day');
            Route::post('habits/{habit}/pause', [HabitApiController::class, 'pause'])->whereNumber('habit')->name('habits.pause');
            Route::post('habits/{habit}/resume', [HabitApiController::class, 'resume'])->whereNumber('habit')->name('habits.resume');
            Route::post('habits/{habit}/archive', [HabitApiController::class, 'archive'])->whereNumber('habit')->name('habits.archive');
        });

        Route::middleware('scope:people:read')->group(function () {
            Route::get('people', [PersonApiController::class, 'index'])->name('people.index');
            Route::get('people/{person}', [PersonApiController::class, 'show'])->whereNumber('person')->name('people.show');
        });
        Route::middleware('scope:people:write')->group(function () {
            Route::post('people', [PersonApiController::class, 'store'])->name('people.store');
            Route::post('people/{person}/catch-ups', [PersonApiController::class, 'catchUp'])->whereNumber('person')->name('people.catch-up');
            Route::post('people/{person}/memories', [PersonApiController::class, 'memory'])->whereNumber('person')->name('people.memory');
            Route::post('people/{person}/follow-up', [PersonApiController::class, 'followUp'])->whereNumber('person')->name('people.follow-up');
            Route::patch('people/{person}', [PersonApiController::class, 'update'])->whereNumber('person')->name('people.update');
            Route::post('people/{person}/archive', [PersonApiController::class, 'archive'])->whereNumber('person')->name('people.archive');
        });

        Route::middleware('scope:github:read')->group(function () {
            Route::get('tasks/{task}/github', [GitHubApiController::class, 'index'])->whereNumber('task')->name('github.index');
            Route::get('tasks/{task}/github/search', [GitHubApiController::class, 'search'])->whereNumber('task')->name('github.search');
            Route::get('projects/{project}/github', [GitHubApiController::class, 'projectRepo'])->whereNumber('project')->name('github.project');
        });
        Route::middleware('scope:github:write')->group(function () {
            Route::post('tasks/{task}/github', [GitHubApiController::class, 'store'])->whereNumber('task')->name('github.store');
            Route::delete('tasks/{task}/github/{link}', [GitHubApiController::class, 'destroy'])->whereNumber(['task', 'link'])->name('github.destroy');
            Route::post('tasks/{task}/github/issue', [GitHubApiController::class, 'createIssue'])->whereNumber('task')->name('github.issue');
            Route::post('tasks/{task}/github/refresh', [GitHubApiController::class, 'refresh'])->whereNumber('task')->name('github.refresh');
            Route::put('projects/{project}/github', [GitHubApiController::class, 'setProjectRepo'])->whereNumber('project')->name('github.set-project');
        });

        Route::middleware('scope:review:read')->group(function () {
            Route::get('review', [OverviewController::class, 'review'])->name('review');
            Route::get('review/weekly', [OverviewController::class, 'weekly'])->name('review.weekly');
        });
    });
});
