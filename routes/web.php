<?php

use App\Http\Controllers\Admin\AdminDashboard;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PromptEdit\GenerationController;
use App\Http\Controllers\PromptEdit\GenerationEstimateController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generate');
    Route::post('generate', [GenerationController::class, 'store'])->name('generate.store');
    Route::post('generate/estimate', GenerationEstimateController::class)
        ->middleware('throttle:120,1')
        ->name('generate.estimate');
    Route::post('credits', [CreditController::class, 'store'])->middleware('throttle:30,1')->name('credits.store');
    Route::get('history', [GenerationController::class, 'index'])->name('history');
    Route::get('history/{generation}', [GenerationController::class, 'show'])
        ->whereUuid('generation')
        ->name('history.show');
    Route::group([
        'prefix' => 'admin',
        'as' => 'admin.',
        'middleware' => [AdminMiddleware::class],
    ], function () {
        Route::get('dashboard', AdminDashboard::class)->name('dashboard');
    });
});

require __DIR__.'/settings.php';
