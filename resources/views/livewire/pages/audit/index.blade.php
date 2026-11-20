<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Kisara PDF', 'url' => '#'], ['label' => 'Riwayat Pegawai']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-kisara-pdf.audit.section.table />
    </x-nawasara-ui::page.container>
</div>
