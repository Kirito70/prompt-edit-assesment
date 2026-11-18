<?php

use App\Http\Controllers\PromptEdit\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('history', [GenerationController::class, 'index'])->name('history');
    Route::get('history/{generation}', [GenerationController::class, 'show'])->name('history.show');
});

require __DIR__.'/settings.php';
