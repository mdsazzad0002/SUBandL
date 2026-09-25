<?php

use Illuminate\Support\Facades\Route;
use SUBandL\Http\Controllers\SubscriptionController as C;

Route::get('/license/verification-required', [C::class, 'verificationRequired'])->name('license.verification-required');
Route::get('/terms', [C::class, 'terms'])->name('license.terms');

Route::get('/subscription', fn () => redirect()->route('subscription.license'))->name('subscription.index');
Route::get('/subscription/license', [C::class, 'subscriptionLicense'])->name('subscription.license');
Route::get('/subscription/update', [C::class, 'subscriptionUpdate'])->name('subscription.update');
Route::get('/subscription/backup', [C::class, 'subscriptionBackup'])->name('subscription.backup');

// Read-only status and the browser health report stay open so the login and
// verification pages can use them.
Route::get('/license/status', [C::class, 'status'])->name('license.status');
Route::post('/license/health-report', [C::class, 'reportHealth'])->name('license.health-report');

Route::middleware('auth')->group(function () {
    Route::post('/license/save', [C::class, 'saveLicense'])->name('license.save');
    Route::post('/license/check', [C::class, 'checkLicense'])->name('license.check');

    Route::post('/license/backup-toggle', [C::class, 'toggleBackup'])->name('license.backup-toggle');
    Route::post('/license/backup/run', [C::class, 'runBackup'])->name('license.backup.run');
    Route::post('/license/backup/auto-check', [C::class, 'autoBackupCheck'])->name('license.backup.auto-check');
    Route::get('/license/backup/status', [C::class, 'backupStatus'])->name('license.backup.status');
    Route::get('/license/backup/history', [C::class, 'backupHistory'])->name('license.backup.history');

    Route::post('/license/update/check', [C::class, 'checkUpdate'])->name('license.update.check');
    Route::post('/license/update/run', [C::class, 'runUpdate'])->name('license.update.run');
    Route::get('/license/update/status', [C::class, 'updateStatus'])->name('license.update.status');
    Route::get('/license/update/history', [C::class, 'updateHistory'])->name('license.update.history');

    if (config('subandl.routes.clear_cache', true)) {
        Route::post('/clear-cache', [C::class, 'clearCache'])->name('cache.clear');
    }
});
