<?php

use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\ModeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::get('settings/mode', [ModeController::class, 'edit'])->name('mode.edit');
    Route::patch('settings/mode', [ModeController::class, 'update'])->name('mode.update');

    // Personal Velocity only: access keys for the API (and, later on this page, GitHub).
    Route::middleware('personal')->group(function () {
        Route::get('settings/integrations', [IntegrationController::class, 'edit'])->name('integrations.edit');
        Route::post('settings/keys', [IntegrationController::class, 'store'])->name('keys.store');
        Route::delete('settings/keys/{id}', [IntegrationController::class, 'destroy'])->whereNumber('id')->name('keys.destroy');
        Route::post('settings/github', [IntegrationController::class, 'connectGitHub'])->name('github.connect');
        Route::delete('settings/github', [IntegrationController::class, 'disconnectGitHub'])->name('github.disconnect');
    });
});
