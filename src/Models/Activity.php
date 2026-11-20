<?php

namespace Nawasara\KisaraPdf\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu pekerjaan Kisara PDF (FR-14), dikirim gateway lewat POST /activities.
 *
 * Tidak memakai LogsActivity: ribuan baris per hari dari akun layanan akan
 * membanjiri activity_log tanpa menambah jejak yang berguna. Yang dicatat ke
 * audit adalah ORANG yang menelusuri riwayat ini (halaman audit).
 *
 * @property int $id
 * @property string $job_id
 * @property string $nip
 * @property string $tool
 * @property string|null $file_name
 * @property int $file_count
 * @property string $status
 * @property string $destination
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class Activity extends Model
{
    protected $table = 'nawasara_kisara_pdf_activities';

    protected $guarded = ['id'];

    protected $casts = [
        'file_count' => 'integer',
        'pages' => 'integer',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Jendela yang boleh terlihat: retention_days terakhir. */
    public function scopeWithinRetention(Builder $query): Builder
    {
        return $query->where('started_at', '>=', self::retentionStart());
    }

    public static function retentionStart(): \Illuminate\Support\Carbon
    {
        $days = max(1, (int) config('nawasara-kisara-pdf.retention_days', 90));

        return now()->utc()->subDays($days);
    }

    public function durationMs(): ?int
    {
        if (! $this->finished_at) {
            return null;
        }

        return max(0, $this->finished_at->getTimestampMs() - $this->started_at->getTimestampMs());
    }
}
