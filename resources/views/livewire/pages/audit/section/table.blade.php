<div>
    <x-nawasara-ui::page-header
        title="Riwayat Pegawai"
        description="Jejak kerja Kisara PDF semua pegawai, 90 hari terakhir: alat, nama berkas, ukuran, hasil, dan tujuan. Isi berkas tidak pernah disimpan. Setiap penelusuran di halaman ini tercatat di Log Aktivitas."
        :count="$rows->total()" />

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['toolFilter' => $toolFilter, 'statusFilter' => $statusFilter]"
                    :labels="['toolFilter' => $this->toolOptions, 'statusFilter' => $this->statusOptions]">
                    <x-nawasara-ui::filter-group label="Alat" model="toolFilter"
                        :items="$this->toolOptions" icon="lucide-wrench" />
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter"
                        :items="$this->statusOptions" icon="lucide-circle-check" />
                </x-nawasara-ui::filter-panel>
            </div>

            {{-- Debounce lebih panjang dari bawaan: tiap kata kunci yang berhenti diketik tercatat di audit. --}}
            <x-nawasara-ui::search-input model="search" :debounce="700" placeholder="Cari NIP atau nama berkas..." />
        </div>

        <div class="flex flex-col sm:flex-row sm:items-end gap-2">
            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Dari Tanggal" wire:model.live="dateFrom" /></div>
            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Sampai Tanggal" wire:model.live="dateTo" /></div>
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if ($rows->total() === 0)
        @if ($this->isFiltered())
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah NIP, nama berkas, alat, status, atau rentang tanggalnya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-inbox"
                title="Belum ada riwayat"
                description="Riwayat muncul setelah gateway Kisara PDF mengirim catatan pekerjaan. Bila pegawai sudah memakai Kisara PDF tetapi tetap kosong, periksa token kisara-pdf.activity.write di gateway (README paket)." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Waktu', 'Pegawai', 'Alat', 'Berkas', 'Hasil', 'Tujuan', '']" stickyLast>
            <x-slot:table>
                @foreach ($rows as $row)
                    <tr wire:key="kpdf-{{ $row->id }}">
                        <td class="px-4 py-3 text-sm whitespace-nowrap text-neutral-700 dark:text-neutral-200">
                            <div>{{ $row->started_at->timezone('Asia/Jakarta')->format('d M Y') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row->started_at->timezone('Asia/Jakarta')->format('H:i:s') }} WIB</div>
                        </td>
                        <td class="px-4 py-3 text-sm font-mono text-neutral-700 dark:text-neutral-200">{{ $row->nip }}</td>
                        <td class="px-4 py-3 text-sm">
                            <x-nawasara-ui::badge color="neutral">{{ \Nawasara\KisaraPdf\Support\Tools::label($row->tool) }}</x-nawasara-ui::badge>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div class="max-w-sm whitespace-normal break-all font-medium text-neutral-800 dark:text-neutral-100">
                                {{ $row->file_name ?? '-' }}@if ($row->file_count > 1) <span class="font-normal text-neutral-500 dark:text-neutral-400">({{ $row->file_count }} berkas)</span>@endif
                            </div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                @if ($row->pages) {{ $row->pages }} halaman · @endif
                                {{ \Nawasara\KisaraPdf\Support\Format::bytes($row->bytes_in) }} → {{ \Nawasara\KisaraPdf\Support\Format::bytes($row->bytes_out) }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <x-nawasara-ui::badge :color="\Nawasara\KisaraPdf\Support\Tools::STATUS_COLORS[$row->status] ?? 'neutral'">
                                {{ \Nawasara\KisaraPdf\Support\Tools::STATUSES[$row->status] ?? $row->status }}
                            </x-nawasara-ui::badge>
                            @if ($row->error_code)
                                <div class="mt-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $row->error_code }}</div>
                            @endif
                            <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ \Nawasara\KisaraPdf\Support\Format::duration($row->durationMs()) }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div>{{ \Nawasara\KisaraPdf\Support\Tools::DESTINATIONS[$row->destination] ?? $row->destination }}</div>
                            @if ($row->drive_path)
                                <div class="max-w-xs whitespace-normal break-all text-xs text-neutral-500 dark:text-neutral-400">{{ $row->drive_path }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                                ['type' => 'click', 'label' => 'Riwayat pegawai ini', 'wire:click' => 'traceEmployee('.$row->id.')', 'icon' => 'lucide-user-search', 'permission' => 'kisara-pdf.activity.audit'],
                            ]" />
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</div>
