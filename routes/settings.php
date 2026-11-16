<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\DataController;
use App\Http\Controllers\Settings\FocusSettingsController;
use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\ModeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PlannerSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TaskSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance');

    Route::get('settings/mode', [ModeController::class, 'edit'])->name('mode.edit');
    Route::patch('settings/mode', [ModeController::class, 'update'])->name('mode.update');

    // Personal Velocity only: access keys for the API (and, later on this page, GitHub).
    Route::middleware('personal')->group(function () {
        Route::get('settings/tasks', [TaskSettingsController::class, 'edit'])->name('task-settings.edit');
        Route::get('settings/focus', [FocusSettingsController::class, 'edit'])->name('focus-settings.edit');
        Route::get('settings/planner', [PlannerSettingsController::class, 'edit'])->name('planner-settings.edit');
        Route::put('settings/planner', [PlannerSettingsController::class, 'update'])->name('planner-settings.update');
        Route::put('settings/focus', [FocusSettingsController::class, 'update'])->name('focus-settings.update');
        Route::patch('settings/tasks', [TaskSettingsController::class, 'update'])->name('task-settings.update');
        Route::get('settings/integrations', [IntegrationController::class, 'edit'])->name('integrations.edit');
        Route::post('settings/keys', [IntegrationController::class, 'store'])->name('keys.store');
        Route::delete('settings/keys/{id}', [IntegrationController::class, 'destroy'])->whereNumber('id')->name('keys.destroy');
        Route::post('settings/github', [IntegrationController::class, 'connectGitHub'])->name('github.connect');
        Route::delete('settings/github', [IntegrationController::class, 'disconnectGitHub'])->name('github.disconnect');
        // Everything as one .zip, and back into an empty account.
        Route::get('settings/data', [DataController::class, 'edit'])->name('data.edit');
        Route::get('settings/data/export', [DataController::class, 'export'])->name('data.export');
        Route::post('settings/data/import', [DataController::class, 'import'])->name('data.import');
        // The wallpaper behind the pages.
        Route::put('settings/wallpaper', [AppearanceController::class, 'update'])->name('wallpaper.update');
        Route::post('settings/wallpaper/picture', [AppearanceController::class, 'upload'])->name('wallpaper.upload');
        Route::delete('settings/wallpaper/picture', [AppearanceController::class, 'destroyPicture'])->name('wallpaper.picture.destroy');
        Route::get('settings/wallpaper/picture', [AppearanceController::class, 'picture'])->name('wallpaper.picture');
    });
});
