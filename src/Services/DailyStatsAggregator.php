<?php

namespace Nawasara\KisaraPdf\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nawasara\KisaraPdf\Models\Activity;
use Nawasara\KisaraPdf\Models\DailyStat;

/**
 * Statistik harian per alat dari tabel riwayat (FR-11, T-14).
 *
 * Satu hari dihitung ULANG utuh (hapus baris hari itu, tulis lagi), bukan
 * ditambah: outbox gateway dapat mengirim catatan berhari-hari terlambat bila
 * Nawasara sempat mati, dan kiriman ulang butir yang sama tidak boleh
 * terhitung dua kali. Menghitung ulang dari sumber membuat keduanya benar.
 *
 * Hari = hari kalender WIB. Durasi dihitung di PHP, bukan SQL, supaya sama di
 * MySQL produksi dan SQLite uji (fungsi selisih waktu keduanya berbeda).
 */
class DailyStatsAggregator
{
    /**
     * Hitung ulang hari-hari terakhir, termasuk kemarin (WIB).
     *
     * @return array<string, int> tanggal => jumlah pekerjaan
     */
    public function aggregateRecent(int $daysBack = 3, ?Carbon $now = null): array
    {
        $today = ($now ?? now())->copy()->setTimezone('Asia/Jakarta')->startOfDay();
        $result = [];

        for ($i = max(1, $daysBack); $i >= 1; $i--) {
            $day = $today->copy()->subDays($i);
            $result[$day->toDateString()] = $this->aggregateDay($day);
        }

        return $result;
    }

    /** @return int jumlah pekerjaan hari itu */
    public function aggregateDay(Carbon $dayWib): int
    {
        $start = Carbon::create($dayWib->year, $dayWib->month, $dayWib->day, 0, 0, 0, 'Asia/Jakarta');
        $from = $start->copy()->utc();
        $to = $start->copy()->addDay()->utc();

        $per = [];
        Activity::query()
            ->select(['id', 'tool', 'status', 'bytes_in', 'started_at', 'finished_at'])
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->lazyById(2000)
            ->each(function (Activity $a) use (&$per) {
                $s = $per[$a->tool] ??= ['jobs' => 0, 'failed' => 0, 'ms' => 0, 'timed' => 0, 'bytes_in' => 0];
                $s['jobs']++;
                $s['failed'] += $a->status === 'failed' ? 1 : 0;
                $s['bytes_in'] += (int) $a->bytes_in;
                if (($ms = $a->durationMs()) !== null) {
                    $s['ms'] += $ms;
                    $s['timed']++;
                }
                $per[$a->tool] = $s;
            });

        $date = $start->toDateString();
        $now = now();

        DB::transaction(function () use ($per, $date, $now) {
            DailyStat::query()->where('date', $date)->delete();

            foreach ($per as $tool => $s) {
                DailyStat::query()->insert([
                    'date' => $date,
                    'tool' => $tool,
                    'jobs' => $s['jobs'],
                    'failed' => $s['failed'],
                    'avg_ms' => $s['timed'] > 0 ? (int) round($s['ms'] / $s['timed']) : null,
                    'bytes_in' => $s['bytes_in'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return array_sum(array_column($per, 'jobs'));
    }
}
