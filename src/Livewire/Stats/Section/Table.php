<?php

namespace Nawasara\KisaraPdf\Livewire\Stats\Section;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\KisaraPdf\Models\DailyStat;
use Nawasara\KisaraPdf\Support\Tools;

/**
 * Statistik pemakaian Kisara PDF (FR-11, T-14), dari tabel agregat harian.
 * Tidak pernah membaca tabel riwayat, jadi halaman ini tidak dapat
 * memperlihatkan NIP atau nama berkas meski izinnya dibagikan lebih luas.
 *
 * Rata-rata durasi rentang = rata-rata harian ditimbang jumlah pekerjaan.
 */
class Table extends Component
{
    public const GROUPS = ['tool' => 'Per alat', 'date' => 'Per hari'];

    #[Url]
    public string $groupBy = 'tool';

    #[Url]
    public string $toolFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function boot(): void
    {
        Gate::authorize('kisara-pdf.stats.view');
    }

    public function mount(): void
    {
        if ($this->dateFrom === '' && $this->dateTo === '') {
            $yesterday = now()->setTimezone('Asia/Jakarta')->subDay();
            $this->dateTo = $yesterday->toDateString();
            $this->dateFrom = $yesterday->copy()->subDays(29)->toDateString();
        }
    }

    #[Computed]
    public function toolOptions(): array
    {
        return Tools::LABELS;
    }

    #[Computed]
    public function groupOptions(): array
    {
        return self::GROUPS;
    }

    public function setGroupBy(string $group): void
    {
        $this->groupBy = array_key_exists($group, self::GROUPS) ? $group : 'tool';
    }

    public function isFiltered(): bool
    {
        return $this->toolFilter !== '';
    }

    /** @return \Illuminate\Support\Collection<int, DailyStat> */
    #[Computed]
    public function stats()
    {
        return DailyStat::query()
            ->when($this->validDate($this->dateFrom), fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($this->validDate($this->dateTo), fn ($q, $d) => $q->where('date', '<=', $d))
            ->when(array_key_exists($this->toolFilter, Tools::LABELS), fn ($q) => $q->where('tool', $this->toolFilter))
            ->orderBy('date')
            ->get();
    }

    /** @return array{jobs: int, failed: int, avg_ms: int|null, bytes_in: int} */
    #[Computed]
    public function totals(): array
    {
        return $this->sum($this->stats);
    }

    /** @return list<array{key: string, label: string, jobs: int, failed: int, avg_ms: int|null, bytes_in: int}> */
    #[Computed]
    public function groups(): array
    {
        $by = $this->groupBy === 'date' ? 'date' : 'tool';

        $groups = $this->stats
            ->groupBy(fn (DailyStat $s) => $by === 'date' ? $s->date->toDateString() : $s->tool)
            ->map(function ($rows, $key) use ($by) {
                $label = $by === 'date'
                    ? Carbon::parse($key)->locale('id')->translatedFormat('D, d M Y')
                    : Tools::label((string) $key);

                return ['key' => (string) $key, 'label' => $label] + $this->sum($rows);
            })
            ->values();

        return ($by === 'date' ? $groups->sortByDesc('key') : $groups->sortByDesc('jobs'))->values()->all();
    }

    private function sum($rows): array
    {
        $jobs = (int) $rows->sum('jobs');
        $timed = $rows->whereNotNull('avg_ms');
        $timedJobs = (int) $timed->sum('jobs');

        return [
            'jobs' => $jobs,
            'failed' => (int) $rows->sum('failed'),
            'avg_ms' => $timedJobs > 0 ? (int) round($timed->sum(fn ($s) => $s->avg_ms * $s->jobs) / $timedJobs) : null,
            'bytes_in' => (int) $rows->sum('bytes_in'),
        ];
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    public function render()
    {
        return view('nawasara-kisara-pdf::livewire.pages.stats.section.table');
    }
}
