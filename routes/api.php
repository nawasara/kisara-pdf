<?php

use Illuminate\Support\Facades\Route;
use Nawasara\KisaraPdf\Http\Api\ActivityController;

/*
| Kisara PDF API (kontrak K-4), dipasang KisaraPdfServiceProvider di
| /api/v1/kisara-pdf dengan api + api.auth + api.log. Pemanggil: gateway
| Kisara PDF di LXC 146 dengan token layanan, tidak pernah peramban.
|
| Dua scope terpisah supaya token yang hanya membaca (mis. untuk uji atau
| alat bantu) tidak dapat menulis riwayat palsu, dan sebaliknya.
*/

Route::middleware('scope:kisara-pdf.activity.write')->group(function () {
    Route::post('/activities', [ActivityController::class, 'store'])->name('activities.store');
});

Route::middleware('scope:kisara-pdf.activity.read')->group(function () {
    Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
});
