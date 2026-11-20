<?php

namespace Nawasara\KisaraPdf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agregat harian per alat (FR-11). Tanpa NIP dan nama berkas.
 *
 * @property \Illuminate\Support\Carbon $date
 * @property string $tool
 * @property int $jobs
 * @property int $failed
 * @property int|null $avg_ms
 * @property int $bytes_in
 */
class DailyStat extends Model
{
    protected $table = 'nawasara_kisara_pdf_daily_stats';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'jobs' => 'integer',
        'failed' => 'integer',
        'avg_ms' => 'integer',
        'bytes_in' => 'integer',
    ];
}
