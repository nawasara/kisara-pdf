<?php

namespace Nawasara\KisaraPdf\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\KisaraPdf\Services\ActivityPruner;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/** Riwayat 90 hari (FR-14) dan statistik 2 tahun (FR-11), dihapus tiap malam. */
class PruneActivitiesJob extends AbstractSyncJob
{
    public int $timeout = 600;

    protected function service(): string { return 'kisara-pdf'; }

    protected function action(): string { return 'prune_activities'; }

    protected function targetType(): ?string { return 'Activity'; }

    protected function targetId(): ?string { return null; }

    protected function execute(): array
    {
        $deleted = app(ActivityPruner::class)->prune();
        Log::info('kisara-pdf: pruned', $deleted);

        return $deleted;
    }
}
