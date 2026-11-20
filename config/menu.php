<?php

$prefix = 'nawasara-kisara-pdf';

/*
| Sidebar Kisara PDF.
|
| Workspace sendiri, BUKAN submenu di 'kisara-workspace'. WorkspaceManager
| mengambil label, ikon, dan izin workspace dari paket yang dimuat pertama
| menurut abjad (vendor/nawasara/*), dan "kisara-pdf" lebih dulu dari
| "workspace". Menumpang berarti label "Kisara Workspace" harus disalin ke sini
| dan berubah diam-diam bila salah satu paket mengubahnya. Kisara PDF juga
| layanan yang terpisah (server, klien Keycloak, dan admin yang berbeda), jadi
| menaruhnya di bawah "Kisara Workspace" menyesatkan (AGENTS.md §5).
|
| Tanpa izin di tingkat workspace: tiap submenu punya izinnya sendiri, jadi
| admin yang hanya boleh melihat statistik tetap melihat workspace ini.
| `group` wajib salah satu WorkspaceManager::GROUP_ORDER.
*/

return [
    [
        'workspace' => 'kisara-pdf',
        'label' => 'Kisara PDF',
        'icon' => 'lucide-file-text',
        'group' => 'Layanan',
        'url' => '',
        'permission' => null,
        'submenu' => [
            [
                'label' => 'Riwayat Pegawai',
                'icon' => 'lucide-history',
                'url' => url($prefix.'/audit'),
                'permission' => 'kisara-pdf.activity.audit',
                'navigate' => true,
            ],
            [
                'label' => 'Statistik',
                'icon' => 'lucide-chart-column',
                'url' => url($prefix.'/stats'),
                'permission' => 'kisara-pdf.stats.view',
                'navigate' => true,
            ],
        ],
    ],
];
