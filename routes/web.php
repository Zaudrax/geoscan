<?php

use App\Http\Controllers\HostController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Access policy
|--------------------------------------------------------------------------
| The application has no accounts: it is a single operator tool, run locally.
| The only protection worth having here is a rate limit, and it follows a
| distinction the application already drew:
|
|   - READ THE ARCHIVE  -> reads the database back, 60 requests/minute.
|   - SPEND A REQUEST   -> sends traffic to shodan.io, throttled much tighter.
|
| The Shodan session lives in .env (SHODAN_SESSION_COOKIE); the throttles are
| what keep a stray refresh from burning the quota it signs.
|
| ORDERING NOTE. "/scans/nouveau" must be declared before "/scans/{scan}",
| otherwise the parameter swallows it and route model binding answers 404.
| Same for "/recherches/nouvelle".
*/

Route::redirect('/', '/scans')->name('home');

/*
|--------------------------------------------------------------------------
| Operations that consume the Shodan quota
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('/recherches/nouvelle', [SearchController::class, 'create'])->name('searches.create');
    Route::post('/recherches', [SearchController::class, 'store'])->name('searches.store');

    Route::get('/hotes', [HostController::class, 'create'])->name('hosts.create');
    Route::post('/hotes', [HostController::class, 'store'])->name('hosts.store');

    // A GET, but a scraping one: every visit attempts a fresh fetch, cooldown
    // permitting. It is a write in disguise, and it is treated as such.
    Route::get('/hotes/{ip}', [HostController::class, 'show'])->name('hosts.show');

    Route::get('/scans/nouveau', [ScanController::class, 'create'])->name('scans.create');

    // Creating a watch scrapes nothing; the scheduler will act later. But it is
    // still an intention to spend quota, so it keeps the tighter limit.
    Route::get('/veilles/nouvelle', [WatchController::class, 'create'])->name('watches.create');
    Route::post('/veilles', [WatchController::class, 'store'])->name('watches.store');
    Route::post('/veilles/{watch}/basculer', [WatchController::class, 'toggle'])->name('watches.toggle');
});

// The most expensive route in the application: up to max_requests outbound
// requests for a single submission, hence its own much tighter limit. It sits
// outside the group on purpose: two throttles stacked on one route share the
// same limiter key and would count every request twice.
Route::post('/scans', [ScanController::class, 'store'])
    ->middleware('throttle:3,1')
    ->name('scans.store');

/*
|--------------------------------------------------------------------------
| Reading the archive -- no outbound traffic to shodan.io
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/recherches', [SearchController::class, 'index'])->name('searches.index');
    Route::get('/recherches/{search}', [SearchController::class, 'show'])->name('searches.show');
    Route::get('/scans', [ScanController::class, 'index'])->name('scans.index');

    // The one exception in this group: rendering a brand new map may resolve a
    // few cities through Photon. Capped by geocoding.max_lookups, cached
    // forever, and unrelated to the Shodan quota.
    Route::get('/scans/{scan}', [ScanController::class, 'show'])->name('scans.show');

    Route::get('/veilles', [WatchController::class, 'index'])->name('watches.index');
    Route::get('/veilles/{watch}', [WatchController::class, 'show'])->name('watches.show');

    // The journal is public on purpose: evidence of responsible crawling that
    // only its author can read does not prove very much.
    Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');
});
