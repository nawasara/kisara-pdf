<?php

namespace Nawasara\KisaraPdf\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\KisaraPdf\Services\DailyStatsAggregator;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Statistik harian (FR-11, T-14). Menghitung ulang beberapa hari terakhir,
 * bukan hanya kemarin, karena catatan dari outbox gateway dapat tiba terlambat.
 */
class AggregateDailyStatsJob extends AbstractSyncJob
{
    public int $timeout = 600;

    protected function service(): string { return 'kisara-pdf'; }

    protected function action(): string { return 'aggregate_daily_stats'; }

    protected function targetType(): ?string { return 'DailyStat'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        $days = (int) config('nawasara-kisara-pdf.scheduler.aggregate_days_back', 3);
        $result = app(DailyStatsAggregator::class)->aggregateRecent($days);
        Log::info('kisara-pdf: daily stats aggregated', ['days' => $result]);

        return ['days' => $result];
    }
}
