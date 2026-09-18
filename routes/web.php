<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoLoginController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('demo/login', [DemoLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('demo.login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/tournaments.php';
require __DIR__.'/admin.php';
require __DIR__.'/public.php';
