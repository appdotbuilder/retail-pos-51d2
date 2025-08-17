<?php

use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health-check', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
})->name('health-check');

// Home page - Main POS Interface
Route::get('/', [PosController::class, 'index'])->name('home');

// POS Routes
Route::controller(PosController::class)->group(function () {
    Route::post('/pos', 'store')->name('pos.store');
    Route::get('/pos/search', 'show')->name('pos.search');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Product management routes
    Route::resource('products', ProductController::class);

    // Report routes
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/salesperson', [ReportController::class, 'show'])->name('salesperson');
        Route::post('/day-close', [ReportController::class, 'store'])->name('day-close');
        Route::get('/closure/{date}', [ReportController::class, 'edit'])->name('closure-report');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
