<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DailyController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\ZonesController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [OverviewController::class, 'index'])->name('overview');

    Route::get('/jobs', [JobsController::class, 'index'])->name('jobs');
    Route::get('/jobs/{runId}', [JobsController::class, 'show'])->name('jobs.show');

    Route::get('/daily', [DailyController::class, 'index'])->name('daily');
    Route::get('/search', [SearchController::class, 'index'])->name('search');

    Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist');
    Route::post('/watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::put('/watchlist/{domain}', [WatchlistController::class, 'update'])->name('watchlist.update');
    Route::delete('/watchlist/{domain}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');
    Route::post('/watchlist/{domain}/notes', [WatchlistController::class, 'storeNote'])->name('watchlist.notes');

    Route::get('/zones', [ZonesController::class, 'index'])->name('zones');

    // JSON endpoints used by the dashboard.
    Route::prefix('api')->name('api.')->group(function (): void {
        Route::get('/overview', [OverviewController::class, 'data'])->name('overview');
        Route::get('/jobs', [JobsController::class, 'data'])->name('jobs');
        Route::get('/jobs/{runId}', [JobsController::class, 'showData'])->name('jobs.show');
        Route::get('/daily', [DailyController::class, 'data'])->name('daily');
        Route::get('/search', [SearchController::class, 'data'])->name('search');
        Route::get('/watchlist', [WatchlistController::class, 'data'])->name('watchlist');
        Route::get('/zones', [ZonesController::class, 'data'])->name('zones');
    });
});
