@use('Nawasara\KisaraPdf\Support\Format')
@php
    $totals = $this->totals;
    $groups = $this->groups;
@endphp
<div>
    <x-nawasara-ui::page-header
        title="Statistik Kisara PDF"
        description="Jumlah pekerjaan per alat per hari, tanpa nama pegawai, NIP, atau nama berkas. Dihitung tiap malam dari riwayat, jadi hari ini belum termasuk."
        :count="count($groups)" />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <x-nawasara-ui::stat-card compact label="Pekerjaan" :value="Format::number($totals['jobs'])" color="primary" icon="lucide-activity" />
        <x-nawasara-ui::stat-card compact label="Gagal" :value="Format::number($totals['failed'])" color="danger" icon="lucide-circle-x"
            :description="$totals['jobs'] > 0 ? number_format($totals['failed'] * 100 / $totals['jobs'], 1, ',', '.').' %' : null" />
        <x-nawasara-ui::stat-card compact label="Rata-rata Durasi" :value="Format::duration($totals['avg_ms'])" color="info" icon="lucide-timer" />
        <x-nawasara-ui::stat-card compact label="Total Unggahan" :value="Format::bytes($totals['bytes_in'])" color="neutral" icon="lucide-hard-drive-upload" />
    </div>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-wrap md:items-end gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['toolFilter' => $toolFilter]"
                    :labels="['toolFilter' => $this->toolOptions]">
                    <x-nawasara-ui::filter-group label="Alat" model="toolFilter"
                        :items="$this->toolOptions" icon="lucide-wrench" />
                </x-nawasara-ui::filter-panel>

                <x-nawasara-ui::segmented-control :options="$this->groupOptions" :active="$groupBy" wire-method="setGroupBy" size="sm" />
            </div>

            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Dari Tanggal" wire:model.live="dateFrom" /></div>
            <div class="sm:w-48"><x-nawasara-ui::form.input type="date" label="Sampai Tanggal" wire:model.live="dateTo" /></div>
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if (count($groups) === 0)
        @if ($this->isFiltered())
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Alat ini belum dipakai pada rentang tanggal tersebut. Ubah alat atau tanggalnya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-chart-column"
                title="Belum ada statistik"
                description="Statistik dihitung tiap malam pukul 00.20 WIB dari riwayat Kisara PDF. Bila riwayat sudah ada tetapi ini tetap kosong, periksa jadwal nawasara-kisara-pdf:aggregate-daily-stats." />
        @endif
    @else
        <x-nawasara-ui::table :headers="[$groupBy === 'date' ? 'Tanggal' : 'Alat', 'Pekerjaan', 'Gagal', 'Rata-rata Durasi', 'Total Unggahan']">
            <x-slot:table>
                @foreach ($groups as $g)
                    <tr wire:key="kpdf-stat-{{ $groupBy }}-{{ $g['key'] }}">
                        <td class="px-4 py-3 text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $g['label'] }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ Format::number($g['jobs']) }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ Format::number($g['failed']) }}
                            @if ($g['jobs'] > 0 && $g['failed'] > 0)
                                <span class="text-xs text-rose-600 dark:text-rose-400">({{ number_format($g['failed'] * 100 / $g['jobs'], 1, ',', '.') }} %)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ Format::duration($g['avg_ms']) }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ Format::bytes($g['bytes_in']) }}</td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif
</div>
