<?php

use App\Http\Controllers\Api\HabitApiController;
use App\Http\Controllers\Api\NoteApiController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\PersonApiController;
use App\Http\Controllers\Api\PlannerApiController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\TaskApiController;
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
        });
        Route::middleware('scope:tasks:write')->group(function () {
            Route::post('tasks', [TaskApiController::class, 'store'])->name('tasks.store');
            Route::patch('tasks/{task}', [TaskApiController::class, 'update'])->whereNumber('task')->name('tasks.update');
            Route::post('tasks/{task}/complete', [TaskApiController::class, 'complete'])->whereNumber('task')->name('tasks.complete');
            Route::post('tasks/{task}/reopen', [TaskApiController::class, 'reopen'])->whereNumber('task')->name('tasks.reopen');
            Route::delete('tasks/{task}', [TaskApiController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
            Route::post('tasks/{id}/restore', [TaskApiController::class, 'restore'])->whereNumber('id')->name('tasks.restore');
            Route::post('tasks/{task}/steps', [TaskApiController::class, 'addStep'])->whereNumber('task')->name('steps.store');
            Route::patch('steps/{step}', [TaskApiController::class, 'updateStep'])->whereNumber('step')->name('steps.update');
            Route::post('focus', [TaskApiController::class, 'startFocus'])->name('focus.start');
            Route::post('focus/stop', [TaskApiController::class, 'stopFocus'])->name('focus.stop');
        });

        Route::middleware('scope:projects:read')->group(function () {
            Route::get('projects', [ProjectApiController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectApiController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::get('goals', [ProjectApiController::class, 'goals'])->name('goals.index');
        });
        Route::middleware('scope:projects:write')->group(function () {
            Route::post('projects', [ProjectApiController::class, 'store'])->name('projects.store');
            Route::patch('projects/{project}', [ProjectApiController::class, 'update'])->whereNumber('project')->name('projects.update');
            Route::patch('milestones/{milestone}', [ProjectApiController::class, 'updateMilestone'])->whereNumber('milestone')->name('milestones.update');
            Route::post('goals', [ProjectApiController::class, 'storeGoal'])->name('goals.store');
            Route::patch('goals/{goal}', [ProjectApiController::class, 'updateGoal'])->whereNumber('goal')->name('goals.update');
        });

        Route::get('planner', [PlannerApiController::class, 'index'])->middleware('scope:planner:read')->name('planner.index');
        Route::middleware('scope:planner:write')->group(function () {
            Route::post('planner/plan', [PlannerApiController::class, 'plan'])->name('planner.plan');
            Route::post('events', [PlannerApiController::class, 'storeEvent'])->name('events.store');
            Route::patch('events/{event}', [PlannerApiController::class, 'updateEvent'])->whereNumber('event')->name('events.update');
            Route::delete('events/{event}', [PlannerApiController::class, 'destroyEvent'])->whereNumber('event')->name('events.destroy');
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
        });

        Route::get('habits', [HabitApiController::class, 'index'])->middleware('scope:habits:read')->name('habits.index');
        Route::middleware('scope:habits:write')->group(function () {
            Route::post('habits', [HabitApiController::class, 'store'])->name('habits.store');
            Route::post('habits/{habit}/check', [HabitApiController::class, 'check'])->whereNumber('habit')->name('habits.check');
            Route::post('habits/{habit}/skip', [HabitApiController::class, 'skip'])->whereNumber('habit')->name('habits.skip');
        });

        Route::middleware('scope:people:read')->group(function () {
            Route::get('people', [PersonApiController::class, 'index'])->name('people.index');
            Route::get('people/{person}', [PersonApiController::class, 'show'])->whereNumber('person')->name('people.show');
        });
        Route::middleware('scope:people:write')->group(function () {
            Route::post('people', [PersonApiController::class, 'store'])->name('people.store');
            Route::post('people/{person}/catch-ups', [PersonApiController::class, 'catchUp'])->whereNumber('person')->name('people.catch-up');
        });

        Route::middleware('scope:review:read')->group(function () {
            Route::get('review', [OverviewController::class, 'review'])->name('review');
            Route::get('review/weekly', [OverviewController::class, 'weekly'])->name('review.weekly');
        });
    });
});
