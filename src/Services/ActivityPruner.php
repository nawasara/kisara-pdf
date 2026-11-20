<?php

namespace Nawasara\KisaraPdf\Services;

use Illuminate\Support\Carbon;
use Nawasara\KisaraPdf\Models\Activity;
use Nawasara\KisaraPdf\Models\DailyStat;

/**
 * Hapus otomatis (FR-14: riwayat 90 hari; FR-11: statistik 2 tahun).
 *
 * Dihapus per potongan 1000 baris berdasarkan id, bukan satu DELETE besar:
 * tabel ini menerima tulisan dari gateway terus-menerus, dan satu DELETE
 * jutaan baris memegang kunci cukup lama untuk membuat POST gateway antre.
 *
 * Angka hari di bawah MINIMUM_DAYS ditolak, bukan dijalankan: salah ketik di
 * env (0, atau 9 untuk 90) tidak boleh berubah menjadi "hapus semuanya".
 */
class ActivityPruner
{
    public const MINIMUM_DAYS = 30;

    private const CHUNK = 1000;

    /** @return array{activities: int, daily_stats: int} */
    public function prune(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();

        return [
            'activities' => $this->pruneActivities($now),
            'daily_stats' => $this->pruneStats($now),
        ];
    }

    private function pruneActivities(Carbon $now): int
    {
        $days = $this->days('retention_days', 90);
        $cutoff = $now->copy()->subDays($days);
        $deleted = 0;

        do {
            $ids = Activity::query()->where('started_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += Activity::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }

    private function pruneStats(Carbon $now): int
    {
        $days = $this->days('stats_retention_days', 730);
        $cutoff = $now->copy()->setTimezone('Asia/Jakarta')->subDays($days)->toDateString();

        return DailyStat::query()->where('date', '<', $cutoff)->delete();
    }

    private function days(string $key, int $default): int
    {
        $days = (int) config('nawasara-kisara-pdf.'.$key, $default);

        if ($days < self::MINIMUM_DAYS) {
            throw new \InvalidArgumentException("nawasara-kisara-pdf.{$key} = {$days} hari ditolak (minimal ".self::MINIMUM_DAYS.').');
        }

        return $days;
    }
}
