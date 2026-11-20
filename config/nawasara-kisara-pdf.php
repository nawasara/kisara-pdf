<?php

return [
    /*
    | Riwayat aktivitas (FR-14). Data internal, disimpan 90 hari lalu dihapus
    | oleh PruneActivitiesJob. GET /activities juga hanya melihat jendela ini,
    | jadi baris yang belum sempat terhapus tidak pernah ikut keluar.
    */
    'retention_days' => (int) env('NAWASARA_KISARA_PDF_RETENTION_DAYS', 90),

    /* Statistik harian (FR-11), tanpa NIP dan nama berkas: 2 tahun. */
    'stats_retention_days' => (int) env('NAWASARA_KISARA_PDF_STATS_RETENTION_DAYS', 730),

    'api' => [
        // K-4: satu POST paling banyak 100 butir, GET paling banyak 50 per halaman.
        'max_batch' => 100,
        'max_per_page' => 50,
        // K-4: waktu boleh maju paling banyak 5 menit (selisih jam gateway).
        'future_tolerance_minutes' => 5,
    ],

    'scheduler' => [
        'enabled' => env('NAWASARA_KISARA_PDF_SCHEDULER', true),
        // Jam dinding WIB (app.timezone = UTC). Agregat sebelum prune.
        'aggregate_at' => '00:20',
        'prune_at' => '03:30',
        // Hari yang dihitung ulang tiap malam: outbox gateway dapat mengirim
        // catatan terlambat bila Nawasara sempat mati (FR-14).
        'aggregate_days_back' => 3,
    ],
];
