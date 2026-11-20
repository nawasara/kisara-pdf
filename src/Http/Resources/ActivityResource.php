<?php

namespace Nawasara\KisaraPdf\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nawasara\KisaraPdf\Models\Activity;

/**
 * Daftar-izin satu catatan riwayat (K-4 GET, AGENTS §10a). Setiap kunci
 * disebut di sini; kolom baru di tabel tidak ikut keluar dengan sendirinya.
 *
 * Yang sengaja TIDAK keluar:
 * - `id`: hanya bermakna di dalam Nawasara; pihak luar merujuk lewat job_id.
 * - `nip`: pemanggil sudah mengirimkannya sebagai saringan, dan respons yang
 *   tidak memuat NIP aman bila tersalin ke log atau tangkapan layar.
 * - `created_at` / `updated_at`: waktu tiba di Nawasara, bukan waktu kerja
 *   pegawai; membocorkan kapan outbox gateway tertahan.
 *
 * Waktu selalu UTC ISO 8601 berakhiran Z (K-3); peramban menampilkan WIB.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Activity $a */
        $a = $this->resource;

        return [
            'job_id' => $a->job_id,
            'tool' => $a->tool,
            'file_name' => $a->file_name,
            'file_count' => $a->file_count,
            'pages' => $a->pages,
            'bytes_in' => $a->bytes_in,
            'bytes_out' => $a->bytes_out,
            'status' => $a->status,
            'error_code' => $a->error_code,
            'destination' => $a->destination,
            'drive_path' => $a->drive_path,
            'started_at' => self::zulu($a->started_at),
            'finished_at' => self::zulu($a->finished_at),
        ];
    }

    private static function zulu(?\DateTimeInterface $at): ?string
    {
        return $at ? \Illuminate\Support\Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
