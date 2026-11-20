<?php
/*
 * Uji T-18 (halaman audit, prune) dan T-14 (statistik harian). SQLite di
 * memori, lewat remote-test:
 *
 *   bash /d/claude-for-work/claude-code-skills/tooling/remote-test.sh -- \
 *     'composer update nawasara/kisara-pdf --no-interaction --no-progress && php packages/nawasara-kisara-pdf/tests/manual/uji-audit-statistik.php'
 *
 * Yang diuji:
 *  - izin: PermissionSeeder; halaman audit dan statistik ditolak tanpa izin,
 *    juga pada update Livewire (boot), bukan hanya saat dibuka
 *  - SETIAP penelusuran tercatat di activity_log (log_name kisara-pdf):
 *    saat dibuka, ganti NIP, ganti halaman; render ulang tanpa perubahan
 *    tidak menggandakan catatan; nama berkas hasil tidak ikut tercatat
 *  - saringan NIP, nama berkas (garis bawah harfiah), alat, status, tanggal
 *    WIB; 90 hari; menu baris; baris di dalam x-slot:table; dua empty state;
 *    tidak ada aksi hapus
 *  - agregat harian: batas hari WIB, gagal, rata-rata durasi, ukuran,
 *    dihitung ulang tanpa ganda, catatan terlambat ikut, tanpa NIP/nama berkas
 *  - prune: > 90 hari dihapus (berkelompok, > 1000 baris), statistik
 *    > 2 tahun dihapus, nilai hari yang keliru ditolak
 *  - job (siklus hidup nawasara/sync), jadwal, menu workspace
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('session.driver', 'array');

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\KisaraPdf\Database\Seeders\PermissionSeeder;
use Nawasara\KisaraPdf\Jobs\AggregateDailyStatsJob;
use Nawasara\KisaraPdf\Jobs\PruneActivitiesJob;
use Nawasara\KisaraPdf\Livewire\Audit\Section\Table as AuditTable;
use Nawasara\KisaraPdf\Livewire\Stats\Section\Table as StatsTable;
use Nawasara\KisaraPdf\Models\Activity;
use Nawasara\KisaraPdf\Models\DailyStat;
use Nawasara\KisaraPdf\Services\ActivityPruner;
use Nawasara\KisaraPdf\Services\DailyStatsAggregator;
use Nawasara\Sync\Models\SyncJob;
use Spatie\Activitylog\Models\Activity as Log;
use Spatie\Permission\Models\Role;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};
$tolak = function (callable $fn): bool {
    try { $fn(); } catch (AuthorizationException) { return true; } catch (\Throwable $e) {
        return $e instanceof Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 403;
    }

    return false;
};

Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('auth_type')->nullable(); $t->string('keycloak_id')->nullable(); $t->string('password')->nullable();
    $t->rememberToken(); $t->timestamps();
});
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-sync/database/migrations/2026_04_25_000001_create_nawasara_sync_jobs_table.php')->up();
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
foreach (glob($root.'/packages/nawasara-kisara-pdf/database/migrations/*.php') as $f) { (require $f)->up(); }

echo "=== izin ===\n";
Role::firstOrCreate(['name' => 'developer', 'guard_name' => 'web']);
(new PermissionSeeder())->run();
$dev = Role::findByName('developer');
$cek('PermissionSeeder: dua izin, diberikan ke developer', $dev->hasPermissionTo('kisara-pdf.activity.audit') && $dev->hasPermissionTo('kisara-pdf.stats.view'));
(new PermissionSeeder())->run();
$cek('seeder aman dijalankan ulang', Spatie\Permission\Models\Permission::where('name', 'like', 'kisara-pdf.%')->count() === 2);

$userModel = config('auth.providers.users.model');
$auditor = $userModel::forceCreate(['name' => 'Admin Keamanan', 'username' => 'auditor', 'email' => 'k@x.id']);
$auditor->givePermissionTo('kisara-pdf.activity.audit');
$statOnly = $userModel::forceCreate(['name' => 'Admin Statistik', 'username' => 'statistik', 'email' => 's@x.id']);
$statOnly->givePermissionTo('kisara-pdf.stats.view');
$nobody = $userModel::forceCreate(['name' => 'Tanpa Izin', 'username' => 'nobody', 'email' => 'n@x.id']);

$cek('tanpa izin: halaman audit ditolak', $tolak(fn () => Livewire::actingAs($nobody)->test(AuditTable::class)));
$cek('izin statistik saja: halaman audit ditolak', $tolak(fn () => Livewire::actingAs($statOnly)->test(AuditTable::class)));
$cek('tanpa izin: halaman statistik ditolak', $tolak(fn () => Livewire::actingAs($nobody)->test(StatsTable::class)));
$cek('izin audit saja: halaman statistik ditolak', $tolak(fn () => Livewire::actingAs($auditor)->test(StatsTable::class)));
$cek('tanpa izin: Index audit ditolak', $tolak(fn () => Livewire::actingAs($nobody)->test(Nawasara\KisaraPdf\Livewire\Audit\Index::class)));
$cek('penolakan tidak menulis catatan audit', Log::where('log_name', 'kisara-pdf')->count() === 0);

// Data: dua pegawai, satu baris berpenanda di luar 90 hari.
$A = '199001012020011001';
$B = '198802022020011002';
$mk = fn (array $o) => Activity::create(array_merge([
    'job_id' => (string) Str::uuid(), 'nip' => $A, 'tool' => 'compress', 'file_name' => 'Surat.pdf', 'file_count' => 1,
    'bytes_in' => 1000, 'bytes_out' => 100, 'status' => 'success', 'destination' => 'download', 'started_at' => now()->subHour(),
], $o));
$mk(['file_name' => 'SK_Bupati_2026.pdf', 'started_at' => now()->subMinutes(5)]);
$mk(['file_name' => 'SKXBupati.pdf', 'started_at' => now()->subMinutes(10)]);
$mk(['tool' => 'merge', 'file_name' => 'Notulen.pdf', 'file_count' => 3, 'status' => 'failed', 'error_code' => 'encrypted', 'started_at' => now()->subDays(2)]);
$mk(['nip' => $B, 'tool' => 'ocr', 'file_name' => 'RAHASIA-Pindaian KTP.pdf', 'destination' => 'drive', 'drive_path' => 'Drive Saya/Hasil Kisara PDF', 'started_at' => now()->subDays(1)]);
$mk(['file_name' => 'PENANDA-KEDALUWARSA.pdf', 'started_at' => now()->subDays(91)]);

echo "\n=== halaman audit + pencatatan penelusuran ===\n";
$logs = fn () => Log::where('log_name', 'kisara-pdf')->orderBy('id')->get();
$t = Livewire::actingAs($auditor)->test(AuditTable::class);
$cek('dibuka tanpa saringan: 4 baris dalam 90 hari', $t->instance()->rows->total() === 4, (string) $t->instance()->rows->total());
$l = $logs();
$cek('membuka halaman tercatat (1 catatan, event searched, pelaku auditor)', $l->count() === 1 && $l[0]->event === 'searched'
    && (int) $l[0]->causer_id === $auditor->id && str_contains($l[0]->description, 'semua pegawai') && $l[0]->properties['results'] === 4, json_encode($l->toArray()));
$t->call('$refresh');
$cek('render ulang tanpa perubahan: tidak ada catatan baru', $logs()->count() === 1);
$t->set('search', $B);
$l = $logs();
$cek('cari NIP tercatat dengan NIP yang ditelusuri', $l->count() === 2 && ($l[1]->properties['nip'] ?? null) === $B && str_contains($l[1]->description, $B), json_encode($l->last()?->toArray()));
$cek('hanya riwayat pegawai itu', $t->instance()->rows->total() === 1 && $t->instance()->rows->first()->nip === $B);
$cek('nama berkas di HASIL tidak disalin ke catatan audit', ! str_contains(json_encode(Log::where('log_name', 'kisara-pdf')->get()->toArray()), 'RAHASIA'));
$t->set('search', '');
$cek('kembali tanpa saringan: tercatat lagi (kumpulan baris berubah)', $logs()->count() === 3);

$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('search', 'SK_');
$cek('cari nama berkas: garis bawah harfiah', $t->instance()->rows->total() === 1 && $t->instance()->rows->first()->file_name === 'SK_Bupati_2026.pdf');
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('search', 'PENANDA');
$cek('baris di luar 90 hari tidak terlihat', $t->instance()->rows->total() === 0);
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('toolFilter', 'merge');
$cek('saring alat', $t->instance()->rows->total() === 1);
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('statusFilter', 'failed');
$cek('saring status', $t->instance()->rows->total() === 1 && $t->instance()->rows->first()->file_name === 'Notulen.pdf');
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('toolFilter', 'tidak-ada');
$cek('saringan alat di luar daftar diabaikan', $t->instance()->rows->total() === 4);
$kemarin = now()->setTimezone('Asia/Jakarta')->subDay()->toDateString();
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->set('dateFrom', $kemarin)->set('dateTo', $kemarin);
$cek('saring tanggal WIB', $t->instance()->rows->total() === (Activity::withinRetention()->get()->filter(fn ($a) => $a->started_at->setTimezone('Asia/Jakarta')->toDateString() === $kemarin)->count()));
$satu = Activity::where('nip', $B)->first();
$t = Livewire::actingAs($auditor)->test(AuditTable::class)->call('traceEmployee', $satu->id);
$cek('menu baris: Riwayat pegawai ini', $t->get('search') === $B && $t->instance()->rows->total() === 1);
$cek('menu baris tidak membuka baris di luar 90 hari', (function () use ($auditor) {
    try { $t = Livewire::actingAs($auditor)->test(AuditTable::class)->call('traceEmployee', Activity::where('file_name', 'PENANDA-KEDALUWARSA.pdf')->value('id')); } catch (\Throwable) { return true; }
    return $t->get('search') === '';
})());
$cek('locked: loggedSignature tidak dapat diisi peramban', (function () use ($auditor) {
    try { Livewire::actingAs($auditor)->test(AuditTable::class)->set('loggedSignature', 'x'); } catch (\Throwable) { return true; }
    return false;
})());
$cek('update Livewire oleh pengguna yang kehilangan izin ditolak (boot)', (function () use ($auditor, $nobody, $tolak) {
    $t = Livewire::actingAs($auditor)->test(AuditTable::class);
    auth()->setUser($nobody);
    return $tolak(fn () => $t->set('search', '1'));
})());
auth()->setUser($auditor);

$html = Livewire::actingAs($auditor)->test(AuditTable::class)->html();
$cek('baris ada di dalam x-slot:table (tbody)', preg_match('#<tbody[^>]*>.*SK_Bupati_2026\.pdf.*</tbody>#s', $html) === 1);
$cek('label alat, status, tujuan, "(3 berkas)" tampil', str_contains($html, 'Gabung') && str_contains($html, 'Gagal') && str_contains($html, 'Kisara Drive') && str_contains($html, '(3 berkas)'));
$cek('tidak ada aksi hapus/ubah', ! str_contains($html, 'lucide-trash') && ! str_contains($html, 'lucide-pencil'));
$cek('kotak cari memakai model="search" + chip filter', str_contains($html, 'wire:model.live.debounce.700ms="search"') && str_contains($html, 'data-filter-chips'), '');
$html = Livewire::actingAs($auditor)->test(AuditTable::class)->set('search', 'tidak-ada-siapa-pun')->html();
$cek('empty state pencarian', str_contains($html, 'Tidak ada yang cocok'));
$jumlahLog = Log::where('log_name', 'kisara-pdf')->count();
Activity::query()->update(['started_at' => now()->subDays(100)]);
$html = Livewire::actingAs($auditor)->test(AuditTable::class)->html();
$cek('empty state tanpa data', str_contains($html, 'Belum ada riwayat'));
$cek('setiap test baru tercatat (total catatan bertambah)', Log::where('log_name', 'kisara-pdf')->count() > $jumlahLog);
$html = Livewire::actingAs($auditor)->test(Nawasara\KisaraPdf\Livewire\Audit\Index::class)->html();
$cek('halaman induk memuat tabel', str_contains($html, 'Riwayat Pegawai'));
Activity::query()->delete();

echo "\n=== agregat harian (T-14) ===\n";
// Hari WIB 2026-11-18 = 2026-11-17 17:00 UTC s/d 2026-11-18 17:00 UTC.
$at = fn (string $utc) => Carbon::parse($utc, 'UTC');
$mk(['tool' => 'compress', 'bytes_in' => 30_000_000, 'started_at' => $at('2026-11-17 17:00:00'), 'finished_at' => $at('2026-11-17 17:00:04')]);
$mk(['tool' => 'compress', 'bytes_in' => 10_000_000, 'started_at' => $at('2026-11-18 16:59:59'), 'finished_at' => $at('2026-11-18 17:00:01')]);
$mk(['tool' => 'compress', 'status' => 'failed', 'bytes_in' => 5, 'started_at' => $at('2026-11-18 03:00:00'), 'finished_at' => null]);
$mk(['nip' => $B, 'tool' => 'ocr', 'bytes_in' => 700, 'started_at' => $at('2026-11-18 04:00:00'), 'finished_at' => $at('2026-11-18 04:01:00')]);
$mk(['tool' => 'compress', 'started_at' => $at('2026-11-17 16:59:59')]);  // 17 Nov WIB
$mk(['tool' => 'compress', 'started_at' => $at('2026-11-18 17:00:00')]);  // 19 Nov WIB
$agg = app(DailyStatsAggregator::class);
$n = $agg->aggregateDay(Carbon::parse('2026-11-18', 'Asia/Jakarta'));
$c = DailyStat::where('date', '2026-11-18')->where('tool', 'compress')->first();
$o = DailyStat::where('date', '2026-11-18')->where('tool', 'ocr')->first();
$cek('batas hari WIB: 4 pekerjaan 18 Nov (17:00 UTC kemarin ikut, 17:00 UTC hari itu tidak)', $n === 4 && $c?->jobs === 3 && $o?->jobs === 1, json_encode(DailyStat::all()->toArray()));
$cek('gagal dihitung', $c->failed === 1 && $o->failed === 0);
$cek('rata-rata durasi hanya dari yang punya finished_at (4 dtk dan 2 dtk = 3000 md)', $c->avg_ms === 3000 && $o->avg_ms === 60000, $c->avg_ms.' / '.$o->avg_ms);
$cek('total ukuran masukan', $c->bytes_in === 40_000_005);
$agg->aggregateDay(Carbon::parse('2026-11-18', 'Asia/Jakarta'));
$cek('dihitung ulang: tidak ganda', DailyStat::where('date', '2026-11-18')->count() === 2 && DailyStat::where('date', '2026-11-18')->where('tool', 'compress')->value('jobs') === 3);
$mk(['tool' => 'merge', 'started_at' => $at('2026-11-18 05:00:00')]);   // catatan terlambat dari outbox
$r = $agg->aggregateRecent(3, Carbon::parse('2026-11-20 10:00', 'Asia/Jakarta'));
$cek('aggregateRecent: 17, 18, 19 Nov WIB, catatan terlambat ikut', array_keys($r) === ['2026-11-17', '2026-11-18', '2026-11-19'] && $r['2026-11-18'] === 5 && DailyStat::where('date', '2026-11-18')->where('tool', 'merge')->value('jobs') === 1, json_encode($r));
Activity::where('tool', 'merge')->delete();
$agg->aggregateDay(Carbon::parse('2026-11-18', 'Asia/Jakarta'));
$cek('alat yang hilang dari sumber ikut hilang dari statistik', ! DailyStat::where('date', '2026-11-18')->where('tool', 'merge')->exists());
$cek('daily_stats tidak memuat NIP', ! str_contains(json_encode(DailyStat::all()->toArray()), $A) && ! str_contains(json_encode(DailyStat::all()->toArray()), $B));

echo "\n=== halaman statistik ===\n";
$s = Livewire::actingAs($statOnly)->test(StatsTable::class)->set('dateFrom', '2026-11-17')->set('dateTo', '2026-11-19');
$tot = $s->instance()->totals;
$cek('total rentang', $tot['jobs'] === 6 && $tot['failed'] === 1, json_encode($tot));
$cek('per alat, terbanyak dulu', array_column($s->instance()->groups, 'key') === ['compress', 'ocr'] && $s->instance()->groups[0]['label'] === 'Kompres', json_encode($s->instance()->groups));
$s->call('setGroupBy', 'date');
$cek('per hari, terbaru dulu', array_column($s->instance()->groups, 'key') === ['2026-11-19', '2026-11-18', '2026-11-17']);
$s->call('setGroupBy', 'apa-saja');
$cek('kelompok tak dikenal jatuh ke per alat', $s->get('groupBy') === 'tool');
$html = $s->html();
$cek('statistik: baris di dalam tbody, tanpa NIP', preg_match('#<tbody[^>]*>.*Kompres.*</tbody>#s', $html) === 1 && ! str_contains($html, $A) && ! str_contains($html, $B));
$html = Livewire::actingAs($statOnly)->test(StatsTable::class)->set('dateFrom', '2026-11-17')->set('dateTo', '2026-11-19')->set('toolFilter', 'redact')->html();
$cek('statistik: empty state saringan', str_contains($html, 'Tidak ada yang cocok'));
$html = Livewire::actingAs($statOnly)->test(StatsTable::class)->set('dateFrom', '2020-01-01')->set('dateTo', '2020-01-02')->html();
$cek('statistik: empty state belum ada', str_contains($html, 'Belum ada statistik'));
$s = Livewire::actingAs($statOnly)->test(StatsTable::class);
$cek('bawaan: 30 hari sampai kemarin WIB', $s->get('dateTo') === now()->setTimezone('Asia/Jakarta')->subDay()->toDateString());
$cek('halaman statistik tidak mencatat audit (tanpa data orang)', Log::where('log_name', 'kisara-pdf')->where('causer_id', $statOnly->id)->count() === 0);

echo "\n=== prune ===\n";
Activity::query()->delete();
$now = now();
$rows = [];
for ($i = 0; $i < 1205; $i++) {
    $rows[] = ['job_id' => 'lama-'.$i.'-'.Str::random(8), 'nip' => $A, 'tool' => 'compress', 'status' => 'success', 'destination' => 'download',
        'file_count' => 1, 'started_at' => $now->copy()->subDays(91)->format('Y-m-d H:i:s'), 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($rows, 300) as $chunk) { DB::table('nawasara_kisara_pdf_activities')->insert($chunk); }
$mk(['file_name' => 'masih-89-hari.pdf', 'started_at' => now()->subDays(89)]);
DailyStat::insert([
    ['date' => now()->setTimezone('Asia/Jakarta')->subDays(731)->toDateString(), 'tool' => 'compress', 'jobs' => 1, 'failed' => 0, 'bytes_in' => 0, 'created_at' => $now, 'updated_at' => $now],
    ['date' => now()->setTimezone('Asia/Jakarta')->subDays(700)->toDateString(), 'tool' => 'compress', 'jobs' => 1, 'failed' => 0, 'bytes_in' => 0, 'created_at' => $now, 'updated_at' => $now],
]);
$statsSebelum = DailyStat::count();
(new PruneActivitiesJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'prune_activities')->latest('id')->first();
$cek('> 90 hari dihapus berkelompok (1205 baris), 89 hari tetap', Activity::count() === 1 && Activity::first()->file_name === 'masih-89-hari.pdf' && ($sj->result['activities'] ?? null) === 1205, json_encode($sj?->result));
$cek('statistik > 2 tahun dihapus, 700 hari tetap', DailyStat::count() === $statsSebelum - 1 && ($sj->result['daily_stats'] ?? null) === 1);
$cek('job prune tercatat sukses di nawasara/sync', $sj->status === SyncJob::STATUS_SUCCESS);
config(['nawasara-kisara-pdf.retention_days' => 9]);
$ditolak = false;
try { app(ActivityPruner::class)->prune(); } catch (\InvalidArgumentException) { $ditolak = true; }
$cek('retention_days 9 (salah ketik 90) ditolak, tidak menghapus', $ditolak && Activity::count() === 1);
config(['nawasara-kisara-pdf.retention_days' => 90]);

(new AggregateDailyStatsJob(triggerSource: 'manual'))->handle();
$sj = SyncJob::where('action', 'aggregate_daily_stats')->latest('id')->first();
$cek('job agregat tercatat sukses', $sj && $sj->status === SyncJob::STATUS_SUCCESS && count($sj->result['days'] ?? []) === 3, json_encode($sj?->result));

echo "\n=== jadwal dan menu ===\n";
$events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
$agE = $events->first(fn ($e) => $e->description === 'nawasara-kisara-pdf:aggregate-daily-stats');
$prE = $events->first(fn ($e) => $e->description === 'nawasara-kisara-pdf:prune-activities');
$cek('agregat 00:20 WIB dan prune 03:30 WIB terjadwal', $agE && $prE && $agE->expression === '20 0 * * *' && $prE->expression === '30 3 * * *'
    && $agE->timezone === 'Asia/Jakarta' && $prE->timezone === 'Asia/Jakarta', $agE?->expression.' / '.$prE?->expression);
$menu = require $root.'/packages/nawasara-kisara-pdf/config/menu.php';
$cek('menu: workspace sendiri kisara-pdf, grup Layanan, 2 submenu berizin', $menu[0]['workspace'] === 'kisara-pdf' && $menu[0]['group'] === 'Layanan'
    && array_column($menu[0]['submenu'], 'permission') === ['kisara-pdf.activity.audit', 'kisara-pdf.stats.view']);
$cek('rute halaman terdaftar', Route::has('nawasara-kisara-pdf.audit') && Route::has('nawasara-kisara-pdf.stats'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
