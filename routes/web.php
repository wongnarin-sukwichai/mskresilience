<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

// The public homepage with each community's live risk level (WelcomeController). `openLogin` is flashed by
// GET /login (AuthenticatedSessionController::create), which has no page of its own.
Route::get('/', WelcomeController::class)->name('home');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
