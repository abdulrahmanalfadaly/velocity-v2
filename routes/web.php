<?php

use App\Http\Controllers\FypController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskStepController;
use App\Http\Controllers\WeightController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home')->name('welcome');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/home')->name('dashboard');

    // Both modes: the report's screens.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::post('tasks/preview', [TaskController::class, 'preview'])->name('tasks.preview');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{id}/restore', [TaskController::class, 'restore'])->whereNumber('id')->name('tasks.restore');

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

        Route::post('tasks/{task}/steps', [TaskStepController::class, 'store'])->name('steps.store');
        Route::patch('steps/{step}', [TaskStepController::class, 'update'])->name('steps.update');
        Route::delete('steps/{step}', [TaskStepController::class, 'destroy'])->name('steps.destroy');
        Route::post('steps/{step}/promote', [TaskStepController::class, 'promote'])->name('steps.promote');

        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
        Route::get('search', SearchController::class)->name('search');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
