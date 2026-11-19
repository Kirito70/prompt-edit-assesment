<?php

use App\Http\Controllers\PromptEdit\GenerationController;
use App\Http\Controllers\PromptEdit\GenerationEstimateController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generate');
    Route::post('generate', [GenerationController::class, 'store'])->name('generate.store');
    Route::post('generate/estimate', GenerationEstimateController::class)
        ->middleware('throttle:120,1')
        ->name('generate.estimate');
    Route::get('history', [GenerationController::class, 'index'])->name('history');
    Route::get('history/{generation}', [GenerationController::class, 'show'])->name('history.show');
});

require __DIR__.'/settings.php';
