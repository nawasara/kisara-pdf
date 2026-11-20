<?php

namespace Nawasara\KisaraPdf\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Izin Kisara PDF di Nawasara (FSD §3).
 *
 * Dua izin yang sengaja terpisah: statistik tidak memuat orang (FR-11), jadi
 * boleh dibagikan ke lebih banyak admin; audit membuka nama berkas pegawai
 * lain (BRD A-05) dan hanya untuk admin keamanan Diskominfo.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'kisara-pdf.activity.audit',   // Riwayat Pegawai: telusuri riwayat semua pegawai (FR-14)
            'kisara-pdf.stats.view',       // Statistik harian per alat (FR-11)
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}
