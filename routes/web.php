<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\CatchUpController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FocusController;
use App\Http\Controllers\FypController;
use App\Http\Controllers\GitHubController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NoteFileController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonDateController;
use App\Http\Controllers\PersonLinkController;
use App\Http\Controllers\PlannerBlockController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskFileController;
use App\Http\Controllers\TaskStepController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WeightController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home')->name('welcome');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/home')->name('dashboard');

    // Both modes: the report's screens.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::post('tasks/preview', [TaskController::class, 'preview'])->name('tasks.preview');
    Route::post('tasks/bulk', [TaskController::class, 'bulk'])->name('tasks.bulk');
    Route::post('tasks/bulk/undo', [TaskController::class, 'bulkUndo'])->name('tasks.bulk-undo');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{id}/restore', [TaskController::class, 'restore'])->whereNumber('id')->name('tasks.restore');
    Route::post('tasks/{task}/revert', [TaskController::class, 'revert'])->name('tasks.revert');

    Route::get('weights', [WeightController::class, 'edit'])->name('weights.edit');
    Route::put('weights', [WeightController::class, 'update'])->name('weights.update');
    Route::delete('weights', [WeightController::class, 'reset'])->name('weights.reset');

    Route::get('history', HistoryController::class)->name('history');

    // FYP mode: the evaluation tools of the first version.
    Route::post('tasks/scenario', [FypController::class, 'scenario'])->name('fyp.scenario');
    Route::get('ranking/export', [FypController::class, 'export'])->name('fyp.export');

    // Personal Velocity only.
    Route::middleware('personal')->group(function () {
        Route::get('home', HomeController::class)->name('home');
        Route::get('tasks/inbox', [TaskController::class, 'inbox'])->name('tasks.inbox');
        Route::get('tasks/waiting', [TaskController::class, 'waiting'])->name('tasks.waiting');

        Route::post('tasks/{task}/steps', [TaskStepController::class, 'store'])->name('steps.store');
        Route::patch('steps/{step}', [TaskStepController::class, 'update'])->name('steps.update');
        Route::delete('steps/{step}', [TaskStepController::class, 'destroy'])->name('steps.destroy');
        Route::post('steps/{step}/promote', [TaskStepController::class, 'promote'])->name('steps.promote');
        Route::post('tasks/{task}/files', [TaskFileController::class, 'store'])->name('files.store');
        Route::post('tasks/{task}/files/remove', [TaskFileController::class, 'removeMany'])->name('files.remove-many');
        Route::get('files/{file}', [TaskFileController::class, 'show'])->name('files.show');
        Route::delete('files/{file}', [TaskFileController::class, 'destroy'])->name('files.destroy');
        Route::post('files/{id}/restore', [TaskFileController::class, 'restore'])->whereNumber('id')->name('files.restore');
        Route::post('steps/{id}/restore', [TaskStepController::class, 'restore'])->whereNumber('id')->name('steps.restore');
        Route::post('tasks/{task}/unpromote', [TaskController::class, 'unpromote'])->name('tasks.unpromote');

        Route::get('tasks/archive', ArchiveController::class)->name('tasks.archive');

        Route::get('tasks/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::patch('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('templates/{template}/use', [TemplateController::class, 'use'])->name('templates.use');
        Route::post('templates/{id}/restore', [TemplateController::class, 'restore'])->whereNumber('id')->name('templates.restore');

        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::post('projects/{project}/unarchive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
        Route::post('projects/{project}/milestones', [MilestoneController::class, 'store'])->name('milestones.store');
        Route::patch('milestones/{milestone}', [MilestoneController::class, 'update'])->name('milestones.update');
        Route::delete('milestones/{milestone}', [MilestoneController::class, 'destroy'])->name('milestones.destroy');
        Route::post('milestones/{id}/restore', [MilestoneController::class, 'restore'])->whereNumber('id')->name('milestones.restore');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
        Route::post('goals', [GoalController::class, 'store'])->name('goals.store');
        Route::patch('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
        Route::post('goals/{goal}/archive', [GoalController::class, 'archive'])->name('goals.archive');
        Route::post('goals/{goal}/unarchive', [GoalController::class, 'unarchive'])->name('goals.unarchive');
        Route::delete('goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

        Route::get('habits', [HabitController::class, 'index'])->name('habits.index');
        Route::post('habits', [HabitController::class, 'store'])->name('habits.store');
        Route::patch('habits/{habit}', [HabitController::class, 'update'])->name('habits.update');
        Route::post('habits/{habit}/archive', [HabitController::class, 'archive'])->name('habits.archive');
        Route::post('habits/{habit}/unarchive', [HabitController::class, 'unarchive'])->name('habits.unarchive');
        Route::delete('habits/{habit}', [HabitController::class, 'destroy'])->name('habits.destroy');
        Route::post('habits/{id}/restore', [HabitController::class, 'restore'])->whereNumber('id')->name('habits.restore');
        Route::post('habits/{habit}/check', [HabitController::class, 'check'])->name('habits.check');
        Route::put('habits/{habit}/day', [HabitController::class, 'day'])->name('habits.day');
        Route::post('habits/{habit}/skip', [HabitController::class, 'skip'])->name('habits.skip');
        Route::post('habits/{habit}/unskip', [HabitController::class, 'unskip'])->name('habits.unskip');
        Route::post('routines', [RoutineController::class, 'store'])->name('routines.store');
        Route::patch('routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
        Route::delete('routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
        Route::post('routines/{id}/restore', [RoutineController::class, 'restore'])->whereNumber('id')->name('routines.restore');

        Route::get('review', [ReviewController::class, 'index'])->name('review.index');
        Route::post('review/weekly', [ReviewController::class, 'done'])->name('review.done');
        Route::delete('review/weekly', [ReviewController::class, 'undone'])->name('review.undone');

        Route::get('people', [PersonController::class, 'index'])->name('people.index');
        Route::post('people', [PersonController::class, 'store'])->name('people.store');
        Route::patch('people/{person}', [PersonController::class, 'update'])->name('people.update');
        Route::put('people/{person}/about', [PersonController::class, 'about'])->name('people.about');
        Route::post('people/{person}/archive', [PersonController::class, 'archive'])->name('people.archive');
        Route::post('people/{person}/unarchive', [PersonController::class, 'unarchive'])->name('people.unarchive');
        Route::delete('people/{person}', [PersonController::class, 'destroy'])->name('people.destroy');
        Route::post('people/{id}/restore', [PersonController::class, 'restore'])->whereNumber('id')->name('people.restore');
        Route::post('people/{person}/dates', [PersonDateController::class, 'store'])->name('person-dates.store');
        Route::patch('person-dates/{date}', [PersonDateController::class, 'update'])->name('person-dates.update');
        Route::delete('person-dates/{date}', [PersonDateController::class, 'destroy'])->name('person-dates.destroy');
        Route::post('person-dates/{id}/restore', [PersonDateController::class, 'restore'])->whereNumber('id')->name('person-dates.restore');
        Route::post('people/{person}/catch-ups', [CatchUpController::class, 'store'])->name('catch-ups.store');
        Route::delete('catch-ups/{catchUp}', [CatchUpController::class, 'destroy'])->name('catch-ups.destroy');
        Route::post('catch-ups/{id}/restore', [CatchUpController::class, 'restore'])->whereNumber('id')->name('catch-ups.restore');
        Route::post('people/{person}/link', [PersonLinkController::class, 'link'])->name('people.link');
        Route::post('people/{person}/unlink', [PersonLinkController::class, 'unlink'])->name('people.unlink');
        Route::post('people/{person}/new-task', [PersonLinkController::class, 'newTask'])->name('people.new-task');
        Route::patch('person-groups/{group}', [PersonLinkController::class, 'renameGroup'])->name('person-groups.update');
        Route::delete('person-groups/{group}', [PersonLinkController::class, 'destroyGroup'])->name('person-groups.destroy');
        Route::post('person-groups/{id}/restore', [PersonLinkController::class, 'restoreGroup'])->whereNumber('id')->name('person-groups.restore');
        Route::get('planner', [PlannerController::class, 'index'])->name('planner.index');
        Route::post('planner/plan', [PlannerBlockController::class, 'plan'])->name('planner.plan');
        Route::post('planner/unplan', [PlannerBlockController::class, 'unplan'])->name('planner.unplan');
        Route::put('planner/hours', [PlannerBlockController::class, 'hours'])->name('planner.hours');
        Route::post('planner/blocks', [PlannerBlockController::class, 'store'])->name('blocks.store');
        Route::patch('planner/blocks/{block}', [PlannerBlockController::class, 'update'])->name('blocks.update');
        Route::delete('planner/blocks/{block}', [PlannerBlockController::class, 'destroy'])->name('blocks.destroy');
        Route::post('planner/blocks/{id}/restore', [PlannerBlockController::class, 'restore'])->whereNumber('id')->name('blocks.restore');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::patch('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
        Route::post('events/{id}/restore', [EventController::class, 'restore'])->whereNumber('id')->name('events.restore');
        Route::post('events/{event}/unskip', [EventController::class, 'unskip'])->name('events.unskip');
        Route::post('focus', [FocusController::class, 'start'])->name('focus.start');
        Route::post('focus/{session}/pause', [FocusController::class, 'pause'])->name('focus.pause');
        Route::post('focus/{session}/resume', [FocusController::class, 'resume'])->name('focus.resume');
        Route::post('focus/{session}/stop', [FocusController::class, 'stop'])->name('focus.stop');
        Route::post('focus/{session}/undo-stop', [FocusController::class, 'undoStop'])->name('focus.undo-stop');
        Route::post('focus/{session}/cancel', [FocusController::class, 'cancel'])->name('focus.cancel');
        Route::get('reminders/due', [ReminderController::class, 'due'])->name('reminders.due');
        Route::post('tasks/{task}/snooze', [ReminderController::class, 'snooze'])->name('tasks.snooze');
        Route::get('search', SearchController::class)->name('search');
        Route::get('search/all', [SearchController::class, 'all'])->name('search.all');

        Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
        Route::post('notes', [NoteController::class, 'store'])->name('notes.store');
        Route::post('notes/journal', [NoteController::class, 'journal'])->name('notes.journal');
        Route::post('notes/event', [NoteController::class, 'forEvent'])->name('notes.event');
        Route::put('notes/{note}/content', [NoteController::class, 'save'])->whereNumber('note')->name('notes.save');
        Route::delete('notes/{note}/discard', [NoteController::class, 'discard'])->whereNumber('note')->name('notes.discard');
        Route::patch('notes/{note}', [NoteController::class, 'update'])->whereNumber('note')->name('notes.update');
        Route::delete('notes/{note}', [NoteController::class, 'destroy'])->whereNumber('note')->name('notes.destroy');
        Route::post('notes/{id}/restore', [NoteController::class, 'restore'])->whereNumber('id')->name('notes.restore');
        Route::post('notes/{note}/tasks', [NoteController::class, 'link'])->whereNumber('note')->name('notes.link');
        Route::delete('notes/{note}/tasks/{task}', [NoteController::class, 'unlink'])->whereNumber('note')->name('notes.unlink');
        Route::post('notes/{note}/make-task', [NoteController::class, 'makeTask'])->whereNumber('note')->name('notes.make-task');
        Route::post('notes/{note}/files', [NoteFileController::class, 'store'])->whereNumber('note')->name('note-files.store');
        Route::get('notes/files/{file}', [NoteFileController::class, 'show'])->whereNumber('file')->name('note-files.show');
        Route::delete('notes/files/{file}', [NoteFileController::class, 'destroy'])->whereNumber('file')->name('note-files.destroy');
        Route::post('notes/files/{id}/restore', [NoteFileController::class, 'restore'])->whereNumber('id')->name('note-files.restore');

        Route::get('github/repos', [GitHubController::class, 'repos'])->name('github.repos');
        Route::put('projects/{project}/github', [GitHubController::class, 'setRepo'])->name('projects.github');
        Route::get('projects/{project}/github/issues', [GitHubController::class, 'issues'])->name('projects.github.issues');
        Route::post('projects/{project}/github/task', [GitHubController::class, 'addTask'])->name('projects.github.task');
        Route::get('tasks/{task}/github/search', [GitHubController::class, 'search'])->name('tasks.github.search');
        Route::post('tasks/{task}/github', [GitHubController::class, 'link'])->name('tasks.github.link');
        Route::post('tasks/{task}/github/refresh', [GitHubController::class, 'refresh'])->name('tasks.github.refresh');
        Route::post('tasks/{task}/github/issue', [GitHubController::class, 'createIssue'])->name('tasks.github.issue');
        Route::delete('github-links/{link}', [GitHubController::class, 'unlink'])->name('github-links.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
