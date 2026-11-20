<?php

namespace Nawasara\KisaraPdf\Livewire\Audit\Section;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\KisaraPdf\Models\Activity;
use Nawasara\KisaraPdf\Services\SearchAudit;
use Nawasara\KisaraPdf\Support\Like;
use Nawasara\KisaraPdf\Support\Tools;

/**
 * Riwayat Pegawai (FR-14): riwayat Kisara PDF semua pegawai untuk admin
 * keamanan dengan izin kisara-pdf.activity.audit. Halaman daftar AGENTS §1a.
 *
 * Setiap kumpulan baris yang berbeda yang diperlihatkan (saringan atau
 * halaman berubah) dicatat ke audit Nawasara lewat SearchAudit, termasuk saat
 * halaman dibuka tanpa saringan: itu pun sudah memperlihatkan nama berkas
 * orang lain.
 *
 * Hanya baca, sengaja tanpa Nonaktifkan/Hapus (pengecualian §1a): riwayat
 * adalah jejak audit; pegawai pun tidak dapat menghapusnya (FR-14), dan
 * baris keluar hanya lewat prune 90 hari.
 */
class Table extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $toolFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    /**
     * Sidik saringan + halaman yang terakhir dicatat. Locked: bila peramban
     * dapat mengisinya, ia dapat membuat penelusuran berikutnya tidak tercatat.
     */
    #[Locked]
    public string $loggedSignature = '';

    /**
     * Setiap permintaan Livewire, bukan hanya saat halaman dibuka: middleware
     * route tidak berjalan pada update (pelajaran audit 15 Oktober 2026).
     */
    public function boot(): void
    {
        Gate::authorize('kisara-pdf.activity.audit');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'toolFilter', 'statusFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function toolOptions(): array
    {
        return Tools::LABELS;
    }

    #[Computed]
    public function statusOptions(): array
    {
        return Tools::STATUSES;
    }

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->toolFilter !== '' || $this->statusFilter !== ''
            || $this->dateFrom !== '' || $this->dateTo !== '';
    }

    #[Computed]
    public function rows()
    {
        $query = Activity::query()->withinRetention();

        if (array_key_exists($this->toolFilter, Tools::LABELS)) {
            $query->where('tool', $this->toolFilter);
        }
        if (array_key_exists($this->statusFilter, Tools::STATUSES)) {
            $query->where('status', $this->statusFilter);
        }

        // Tanggal diketik dalam WIB; started_at tersimpan UTC.
        if ($from = $this->day($this->dateFrom)) {
            $query->where('started_at', '>=', $from->startOfDay()->utc());
        }
        if ($to = $this->day($this->dateTo)) {
            $query->where('started_at', '<=', $to->endOfDay()->utc());
        }

        $term = trim($this->search);
        if ($term !== '') {
            if (ctype_digit($term)) {
                // NIP utuh atau awalannya; indeks (nip, started_at) terpakai.
                $query->where('nip', 'like', $term.'%');
            } else {
                Like::contains($query, 'file_name', $term);
            }
        }

        return $query->orderByDesc('started_at')->orderByDesc('id')->paginate(50);
    }

    /** Menu baris: semua riwayat pegawai ini. */
    public function traceEmployee(int $id): void
    {
        $row = Activity::query()->withinRetention()->findOrFail($id);
        $this->search = $row->nip;
        $this->toolFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    private function day(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, 'Asia/Jakarta');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Catat bila kumpulan baris yang diperlihatkan berbeda dari yang terakhir dicatat. */
    private function auditView(int $page, int $total): void
    {
        $filters = [
            'search' => trim($this->search),
            'tool' => $this->toolFilter,
            'status' => $this->statusFilter,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ];
        $signature = md5(json_encode([$filters, $page]));

        if ($signature === $this->loggedSignature) {
            return;
        }

        app(SearchAudit::class)->record(auth()->user(), $filters, $page, $total);
        $this->loggedSignature = $signature;
    }

    public function render()
    {
        $rows = $this->rows;
        $this->auditView($rows->currentPage(), $rows->total());

        return view('nawasara-kisara-pdf::livewire.pages.audit.section.table', ['rows' => $rows]);
    }
}
