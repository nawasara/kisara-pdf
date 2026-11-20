<?php

namespace Nawasara\KisaraPdf\Livewire\Stats;

use Livewire\Component;

/** Statistik Kisara PDF: kerangka halaman saja (AGENTS §1b). */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('kisara-pdf.stats.view');
    }

    public function render()
    {
        return view('nawasara-kisara-pdf::livewire.pages.stats.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
