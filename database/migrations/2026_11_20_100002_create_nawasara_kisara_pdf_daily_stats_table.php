<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Statistik harian per alat (FR-11). Sengaja TANPA nip, file_name, atau IP:
| tabel ini disimpan 2 tahun, jauh melewati umur riwayat 90 hari, jadi tidak
| boleh memuat apa pun yang menunjuk ke orang.
|
| `date` adalah hari kalender WIB, karena itu yang dibaca admin.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_kisara_pdf_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('tool', 32);
            $table->unsignedInteger('jobs')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('avg_ms')->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->timestamps();

            $table->unique(['date', 'tool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_kisara_pdf_daily_stats');
    }
};
