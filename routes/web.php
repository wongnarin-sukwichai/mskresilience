<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// `openLogin` is flashed by GET /login (AuthenticatedSessionController::create), which has no page of its own.
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'openLogin' => (bool) session('openLogin'),
    ]);
})->name('home');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
