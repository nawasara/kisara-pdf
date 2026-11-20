<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara PDF', 'url' => '#'], ['label' => 'Statistik']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-kisara-pdf.stats.section.table />
    </x-nawasara-ui::page.container>
</div>
