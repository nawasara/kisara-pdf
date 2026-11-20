<?php

namespace Nawasara\KisaraPdf\Livewire\Audit;

use Livewire\Component;

/** Riwayat Pegawai: kerangka halaman saja; daftarnya komponen sendiri (AGENTS §1b). */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('kisara-pdf.activity.audit');
    }

    public function render()
    {
        return view('nawasara-kisara-pdf::livewire.pages.audit.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
