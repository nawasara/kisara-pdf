<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Riwayat aktivitas Kisara PDF (FR-14, kontrak K-4).
|
| bigint, bukan UUID (AGENTS §10a): tabel internal yang tumbuh cepat dan
| dihapus setelah 90 hari. Id barisnya tidak pernah keluar; pihak luar
| merujuk lewat job_id.
|
| file_name TEXT: nama berkas dinas bisa sangat panjang (K-4 mengizinkan
| sampai 1000 karakter) dan disimpan apa adanya (BRD A-05).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_kisara_pdf_activities', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 64)->unique();
            $table->string('nip', 18);
            $table->string('tool', 32);
            $table->text('file_name')->nullable();
            $table->unsignedInteger('file_count')->default(1);
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedBigInteger('bytes_in')->nullable();
            $table->unsignedBigInteger('bytes_out')->nullable();
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->string('destination', 16)->default('download');
            $table->text('drive_path')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Riwayat satu pegawai (GET /activities), terbaru dulu.
            $table->index(['nip', 'started_at']);
            // Prune, agregat harian, dan halaman audit tanpa saringan NIP.
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_kisara_pdf_activities');
    }
};
