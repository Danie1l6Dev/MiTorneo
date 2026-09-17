<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TournamentController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

    Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments.index');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings/toggle-sanction-pdf-uploads', [SettingsController::class, 'toggleSanctionPdfUploads'])->name('settings.toggle-sanction-pdf-uploads');
});
