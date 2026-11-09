<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DuplicateController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SourceActionController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\UnrecognizedValueController;
use Illuminate\Support\Facades\Route;

// ---- sign in / out ----
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// ---- signed in as the administrator, second factor not yet checked ----
Route::middleware(['auth', 'admin'])->prefix('two-factor')->name('two-factor.')->controller(TwoFactorController::class)->group(function () {
    Route::get('/', 'challenge')->name('challenge');
    Route::post('/', 'verify')->middleware('throttle:two-factor')->name('verify');
    Route::get('setup', 'setup')->name('setup');
    Route::post('setup', 'confirm')->middleware('throttle:two-factor')->name('confirm');
    Route::get('recovery-codes', 'recovery')->name('recovery');
});

// ---- the app: password, plus the authenticator code when two-factor has been turned on ----
Route::middleware(['auth', 'admin', 'two-factor'])->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::post('/security/two-factor/disable', [SecurityController::class, 'disable'])->middleware('throttle:two-factor')->name('security.disable');
    Route::post('/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->middleware('throttle:two-factor')->name('security.recovery');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');

    Route::get('/qualifications', [QualificationController::class, 'index'])->name('qualifications.index');

    Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
    Route::get('/directory/{qualification}', [DirectoryController::class, 'show'])->whereNumber('qualification')->name('directory.show');

    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('/jobs/{job}', [JobController::class, 'show'])->whereNumber('job')->name('jobs.show');
    Route::post('/jobs/{job}/status', [JobController::class, 'decide'])->whereNumber('job')->name('jobs.status');
    Route::post('/jobs/{job}/approve', [JobController::class, 'approve'])->whereNumber('job')->name('jobs.approve');
    Route::post('/jobs/{job}/separate', [JobController::class, 'separate'])->whereNumber('job')->name('jobs.separate');

    Route::get('/duplicates', [DuplicateController::class, 'index'])->name('duplicates.index');
    Route::post('/duplicates/{pair}/confirm', [DuplicateController::class, 'confirm'])->whereNumber('pair')->name('duplicates.confirm');
    Route::post('/duplicates/{pair}/reject', [DuplicateController::class, 'reject'])->whereNumber('pair')->name('duplicates.reject');

    Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('/review', [ReviewController::class, 'store'])->name('review.store');

    Route::get('/values',[UnrecognizedValueController::class, 'index'])->name('values.index');
    Route::post('/values', [UnrecognizedValueController::class, 'store'])->name('values.store');

    Route::resource('sources', SourceController::class);
    Route::prefix('sources/{source}')->name('sources.')->controller(SourceActionController::class)->group(function () {
        Route::post('access', 'decideAccess')->name('access');
        Route::post('activate', 'activate')->name('activate');
        Route::post('deactivate', 'deactivate')->name('deactivate');
        Route::post('test', 'test')->name('test');
        Route::post('run', 'run')->name('run');
        Route::post('ads', 'addAd')->name('ads');
        Route::post('import', 'importFile')->name('import');
        Route::get('import-sample', 'importSample')->name('import-sample');
        Route::post('preview', 'previewExtraction')->name('preview');
    });
});
