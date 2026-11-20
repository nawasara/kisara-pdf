<?php

namespace Nawasara\KisaraPdf\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat setiap penelusuran riwayat Kisara PDF oleh admin ke audit Nawasara
 * (activity_log spatie, tampil di halaman Log Aktivitas nawasara-audit).
 * FR-14: "setiap penelusuran itu sendiri tercatat".
 *
 * Yang dicatat: siapa, saringan apa (termasuk NIP dan kata kunci yang dicari),
 * halaman ke berapa, dan jumlah hasil. Nama berkas di HASIL tidak ikut: jejak
 * audit cukup menjawab "siapa melihat riwayat siapa", dan menyalin nama
 * berkas ke activity_log membuatnya hidup lebih lama dari 90 hari.
 */
class SearchAudit
{
    public const LOG_NAME = 'kisara-pdf';

    public const EVENT = 'searched';

    /** @param  array<string, string>  $filters */
    public function record(?Authenticatable $user, array $filters, int $page, int $total): void
    {
        $filters = array_filter($filters, fn ($v) => $v !== '' && $v !== null);
        $nip = isset($filters['search']) && preg_match('/^\d{18}$/', $filters['search']) ? $filters['search'] : null;

        $log = activity(self::LOG_NAME)->event(self::EVENT)->withProperties(array_filter([
            'filters' => $filters,
            'nip' => $nip,
            'page' => $page,
            'results' => $total,
        ], fn ($v) => $v !== null));

        if ($user instanceof Model) {
            $log->causedBy($user);
        }

        $log->log($nip
            ? "Menelusuri riwayat Kisara PDF pegawai {$nip}"
            : ($filters === [] ? 'Membuka riwayat Kisara PDF semua pegawai' : 'Menelusuri riwayat Kisara PDF'));
    }
}
