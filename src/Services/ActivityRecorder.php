<?php

namespace Nawasara\KisaraPdf\Services;

use Illuminate\Support\Carbon;
use Nawasara\KisaraPdf\Models\Activity;

/**
 * Menyimpan butir riwayat dari gateway (K-4 POST /activities).
 *
 * Idempoten per job_id lewat upsert: outbox gateway boleh mengirim ulang
 * kapan saja, dan Simpan ke Drive (T-17) mengirim ulang butir yang sama
 * dengan destination baru. Kiriman ulang memperbarui baris yang sama.
 *
 * `nip` TIDAK ikut diperbarui. Satu pekerjaan milik satu pegawai sejak awal;
 * membiarkan kiriman ulang menggantinya berarti satu galat di gateway dapat
 * memindahkan riwayat seseorang ke riwayat orang lain.
 */
class ActivityRecorder
{
    public const UPDATABLE = [
        'tool', 'file_name', 'file_count', 'pages', 'bytes_in', 'bytes_out',
        'status', 'error_code', 'destination', 'drive_path', 'started_at', 'finished_at',
    ];

    /**
     * @param  list<array<string, mixed>>  $items  butir yang SUDAH divalidasi
     * @return int jumlah job_id unik yang disimpan
     */
    public function record(array $items): int
    {
        // Butir ganda dalam satu batch: yang terakhir menang, seperti bila
        // keduanya dikirim berurutan. Juga mencegah upsert menyentuh baris
        // yang sama dua kali dalam satu pernyataan.
        $rows = [];
        foreach ($items as $item) {
            $rows[(string) $item['job_id']] = [
                'job_id' => (string) $item['job_id'],
                'nip' => (string) $item['nip'],
                'tool' => (string) $item['tool'],
                'file_name' => $item['file_name'] ?? null,
                'file_count' => (int) ($item['file_count'] ?? 1),
                'pages' => isset($item['pages']) ? (int) $item['pages'] : null,
                'bytes_in' => isset($item['bytes_in']) ? (int) $item['bytes_in'] : null,
                'bytes_out' => isset($item['bytes_out']) ? (int) $item['bytes_out'] : null,
                'status' => (string) $item['status'],
                'error_code' => $item['error_code'] ?? null,
                'destination' => (string) ($item['destination'] ?? 'download'),
                'drive_path' => $item['drive_path'] ?? null,
                'started_at' => self::utc($item['started_at']),
                'finished_at' => isset($item['finished_at']) ? self::utc($item['finished_at']) : null,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        Activity::query()->upsert(array_values($rows), ['job_id'], self::UPDATABLE);

        return count($rows);
    }

    /**
     * ISO 8601 ber-offset ke jam UTC untuk kolom timestamp. Tanpa ->utc(),
     * "2026-11-20T10:32:10+07:00" tersimpan sebagai 10:32 (jam dinding),
     * tujuh jam meleset (memori reference_carbon_offset_eloquent_trap).
     */
    public static function utc(string $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
