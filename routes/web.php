<?php

use Illuminate\Support\Facades\Route;
use Nawasara\KisaraPdf\Livewire\Audit\Index as AuditIndex;
use Nawasara\KisaraPdf\Livewire\Stats\Index as StatsIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-kisara-pdf')->group(function () {
    Route::get('audit', AuditIndex::class)
        ->middleware(PermissionMiddleware::using('kisara-pdf.activity.audit'))
        ->name('nawasara-kisara-pdf.audit');

    Route::get('stats', StatsIndex::class)
        ->middleware(PermissionMiddleware::using('kisara-pdf.stats.view'))
        ->name('nawasara-kisara-pdf.stats');
});
