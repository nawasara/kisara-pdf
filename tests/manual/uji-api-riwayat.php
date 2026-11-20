<?php
/*
 * Uji T-18: API riwayat Kisara PDF (kontrak K-4) lewat permintaan HTTP
 * sungguhan (api.auth + scope). SQLite di memori, lewat remote-test:
 *
 *   bash /d/claude-for-work/claude-code-skills/tooling/remote-test.sh -- \
 *     'composer update nawasara/kisara-pdf --no-interaction --no-progress && php packages/nawasara-kisara-pdf/tests/manual/uji-api-riwayat.php'
 *
 * Yang diuji:
 *  - rute, nama rute tanpa dobel domain, scope terdaftar, 401/403 per scope
 *  - POST: batch tersimpan, waktu ber-offset jadi UTC, idempoten per job_id,
 *    kiriman ulang memperbarui tujuan tetapi tidak memindahkan NIP, butir
 *    ganda dalam satu batch, kunci asing dibuang
 *  - validasi K-4: alat K-5, NIP 18 digit, file_name ≤ 1000, waktu ≤ 5 menit
 *    ke depan, selesai sebelum mulai, batch ≤ 100, satu butir salah = tidak
 *    ada yang disimpan dan galat menunjuk indeksnya
 *  - GET: hanya NIP itu, 90 hari, saringan, q (nama berkas, label alat,
 *    garis bawah harfiah), paginasi dan per_page ≤ 50
 *  - KEBOCORAN: data berpenanda milik pegawai lain dan yang kedaluwarsa tidak
 *    muncul di badan jawaban; kunci persis daftar-izin; id, nip, created_at
 *    tidak keluar; nama berkas tidak masuk log teknis
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nawasara\Api\Services\TokenManager;
use Nawasara\KisaraPdf\Models\Activity;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('password')->nullable(); $t->timestamps();
});
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-kisara-pdf/database/migrations/*.php') as $f) { (require $f)->up(); }
$cek('migrasi jalan', Schema::hasTable('nawasara_kisara_pdf_activities') && Schema::hasTable('nawasara_kisara_pdf_daily_stats'));
$cek('daily_stats tanpa kolom nip / file_name', ! Schema::hasColumn('nawasara_kisara_pdf_daily_stats', 'nip') && ! Schema::hasColumn('nawasara_kisara_pdf_daily_stats', 'file_name'));

// Semua log teknis ditampung untuk uji "nama berkas tidak masuk log".
$logs = [];
Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) { $logs[] = $e->message.' '.json_encode($e->context); });

$tokens = $app->make(TokenManager::class);
$tWrite = $tokens->create('uji tulis', ['kisara-pdf.activity.write'])['plaintext'];
$tRead = $tokens->create('uji baca', ['kisara-pdf.activity.read'])['plaintext'];
$tBoth = $tokens->create('uji gateway', ['kisara-pdf.activity.write', 'kisara-pdf.activity.read'])['plaintext'];

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$call = function (string $method, string $uri, ?array $json, string $token) use ($http) {
    $server = ['REMOTE_ADDR' => '10.1.1.38', 'HTTP_ACCEPT' => 'application/json'];
    if ($token !== '') { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    $content = null;
    if ($json !== null) { $server['CONTENT_TYPE'] = 'application/json'; $content = json_encode($json); }

    return $http->handle(Request::create('/api/v1/kisara-pdf'.$uri, $method, [], [], [], $server, $content));
};
$post = fn (array $items, ?string $token = null) => $call('POST', '/activities', ['items' => $items], $token ?? $tWrite);
$get = fn (array $q, ?string $token = null) => $call('GET', '/activities?'.http_build_query($q), null, $token ?? $tRead);
$body = fn ($res) => json_decode($res->getContent(), true);

$A = '199001012020011001';
$B = '198802022020011002';
$seq = 0;
$item = function (array $over = []) use ($A, &$seq) {
    $seq++;

    return array_merge([
        'job_id' => sprintf('0b8f5c2e-1111-4a6b-9c1d-%012d', $seq), 'nip' => $A, 'tool' => 'compress',
        'file_name' => 'Pindaian SK.pdf', 'file_count' => 1, 'pages' => 8,
        'bytes_in' => 37121000, 'bytes_out' => 420000, 'status' => 'success',
        'error_code' => null, 'destination' => 'download', 'drive_path' => null,
        'started_at' => now()->utc()->subMinutes(10)->format('Y-m-d\TH:i:s\Z'),
        'finished_at' => now()->utc()->subMinutes(10)->addSeconds(5)->format('Y-m-d\TH:i:s\Z'),
    ], $over);
};

echo "=== rute dan scope ===\n";
$rs = Route::getRoutes()->getByName('nawasara-api.kisara-pdf.activities.store');
$ri = Route::getRoutes()->getByName('nawasara-api.kisara-pdf.activities.index');
$cek('POST dan GET di /api/v1/kisara-pdf/activities', $rs?->uri() === 'api/v1/kisara-pdf/activities' && $ri?->uri() === 'api/v1/kisara-pdf/activities');
$scopes = $app->make(Nawasara\Api\Support\ScopeRegistry::class)->all();
$cek('kedua scope terdaftar', array_key_exists('kisara-pdf.activity.write', $scopes) && array_key_exists('kisara-pdf.activity.read', $scopes));
$cek('tanpa token: POST 401', $post([$item()], '')->getStatusCode() === 401);
$cek('tanpa token: GET 401', $get(['nip' => $A], '')->getStatusCode() === 401);
$cek('token baca saja tidak dapat menulis: 403', $post([$item()], $tRead)->getStatusCode() === 403);
$cek('token tulis saja tidak dapat membaca: 403', $get(['nip' => $A], $tWrite)->getStatusCode() === 403);
$cek('belum ada yang tersimpan dari permintaan yang ditolak', Activity::count() === 0);

echo "\n=== POST ===\n";
$i1 = $item(); $i2 = $item(['tool' => 'merge', 'file_name' => 'Laporan Q3.pdf', 'file_count' => 3]);
$i3 = $item(['tool' => 'ocr', 'status' => 'failed', 'error_code' => 'session_expired', 'bytes_out' => null, 'finished_at' => null,
    'started_at' => Carbon::now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i:sP')]);
$r = $post([$i1, $i2, $i3], $tBoth);
$cek('batch 3 butir: 200 accepted 3', $r->getStatusCode() === 200 && $body($r) === ['data' => ['accepted' => 3]], $r->getContent());
$row3 = Activity::where('job_id', $i3['job_id'])->first();
$cek('waktu +07:00 disimpan sebagai UTC', $row3 && $row3->started_at->eq(Carbon::parse($i3['started_at'])) && $row3->getRawOriginal('started_at') === Carbon::parse($i3['started_at'])->utc()->format('Y-m-d H:i:s'), (string) $row3?->getRawOriginal('started_at'));
$cek('gagal tanpa finished_at tersimpan', $row3->status === 'failed' && $row3->finished_at === null && $row3->error_code === 'session_expired');

$ulang = array_merge($i1, ['nip' => $B, 'destination' => 'drive', 'drive_path' => 'Drive Saya/Hasil Kisara PDF/Pindaian SK.pdf']);
$r = $post([$ulang]);
$row1 = Activity::where('job_id', $i1['job_id'])->first();
$cek('kirim ulang job_id yang sama: tetap satu baris', $r->getStatusCode() === 200 && Activity::where('job_id', $i1['job_id'])->count() === 1 && Activity::count() === 3);
$cek('kirim ulang memperbarui tujuan (Simpan ke Drive)', $row1->destination === 'drive' && str_ends_with((string) $row1->drive_path, 'Pindaian SK.pdf'));
$cek('kirim ulang TIDAK memindahkan NIP', $row1->nip === $A);

$d = $item();
$r = $post([$d, array_merge($d, ['status' => 'failed', 'error_code' => 'x'])]);
$cek('job_id ganda dalam satu batch: accepted 1, yang terakhir menang', ($body($r)['data']['accepted'] ?? null) === 1 && Activity::where('job_id', $d['job_id'])->value('status') === 'failed');
$asing = $item(['secret' => 'JANGAN-DISIMPAN']);
$post([$asing]);
$cek('kunci di luar kontrak dibuang', ! str_contains(json_encode(Activity::where('job_id', $asing['job_id'])->first()->getAttributes()), 'JANGAN-DISIMPAN'));

echo "\n=== validasi ===\n";
$sebelum = Activity::count();
$r = $post([$item(), $item(['tool' => 'url-to-pdf'])]);
$cek('alat di luar K-5: 422, galat menunjuk items.1.tool', $r->getStatusCode() === 422 && ($body($r)['error']['code'] ?? '') === 'validation_failed' && array_key_exists('items.1.tool', $body($r)['error']['fields'] ?? []), $r->getContent());
$cek('satu butir salah: butir sah di batch yang sama TIDAK disimpan', Activity::count() === $sebelum);
$cek('NIP 17 digit: 422', $post([$item(['nip' => '19950614202012100'])])->getStatusCode() === 422);
$cek('NIP berhuruf: 422', $post([$item(['nip' => '19950614202012100x'])])->getStatusCode() === 422);
$cek('file_name 1001 karakter: 422', $post([$item(['file_name' => str_repeat('a', 997).'.pdf!'])])->getStatusCode() === 422);
$panjang = str_repeat('Keputusan Bupati Ponorogo ', 40).'x.pdf';
$panjang = mb_substr($panjang, 0, 1000);
$r = $post([$p = $item(['file_name' => $panjang])]);
$cek('file_name tepat 1000 karakter: diterima utuh (text)', $r->getStatusCode() === 200 && Activity::where('job_id', $p['job_id'])->value('file_name') === $panjang);
$cek('started_at 6 menit ke depan: 422', $post([$item(['started_at' => now()->utc()->addMinutes(6)->format('Y-m-d\TH:i:s\Z'), 'finished_at' => null])])->getStatusCode() === 422);
$cek('finished_at 6 menit ke depan: 422', $post([$item(['finished_at' => now()->utc()->addMinutes(6)->format('Y-m-d\TH:i:s\Z')])])->getStatusCode() === 422);
$cek('4 menit ke depan (selisih jam): diterima', $post([$item(['started_at' => now()->utc()->addMinutes(4)->format('Y-m-d\TH:i:s\Z'), 'finished_at' => null])])->getStatusCode() === 200);
$cek('selesai sebelum mulai: 422', $post([$item(['finished_at' => now()->utc()->subMinutes(20)->format('Y-m-d\TH:i:s\Z')])])->getStatusCode() === 422);
$cek('waktu bukan tanggal: 422', $post([$item(['started_at' => 'kemarin sore'])])->getStatusCode() === 422);
$cek('status di luar daftar: 422', $post([$item(['status' => 'pending'])])->getStatusCode() === 422);
// Kontrak v1.1: error_code teks bebas <= 64 (daftar kanonik K-3 hanya panduan).
$r = $post([$p = $item(['status' => 'failed', 'error_code' => 'http_502']), $p2 = $item(['status' => 'failed', 'error_code' => str_repeat('e', 64)])]);
$cek('error_code kanonik http_502 dan teks bebas 64 karakter: diterima', $r->getStatusCode() === 200
    && Activity::where('job_id', $p['job_id'])->value('error_code') === 'http_502' && strlen((string) Activity::where('job_id', $p2['job_id'])->value('error_code')) === 64, $r->getContent());
$cek('error_code 65 karakter: 422', $post([$item(['status' => 'failed', 'error_code' => str_repeat('e', 65)])])->getStatusCode() === 422);
$cek('destination di luar daftar: 422', $post([$item(['destination' => 'email'])])->getStatusCode() === 422);
$cek('items kosong: 422', $post([])->getStatusCode() === 422);
$cek('tanpa items: 422', $call('POST', '/activities', ['data' => []], $tWrite)->getStatusCode() === 422);
$cek('101 butir: 422', $post(array_map(fn () => $item(), range(1, 101)))->getStatusCode() === 422);
$r = $post(array_map(fn () => $item(), range(1, 100)));
$cek('100 butir: diterima semua', ($body($r)['data']['accepted'] ?? null) === 100, $r->getContent());

echo "\n=== GET ===\n";
Activity::query()->delete();
$mk = fn (array $o) => Activity::create(array_merge([
    'job_id' => (string) Illuminate\Support\Str::uuid(), 'nip' => $A, 'tool' => 'compress', 'file_name' => 'Surat.pdf',
    'file_count' => 1, 'status' => 'success', 'destination' => 'download', 'started_at' => now()->subHour(),
], $o));
$mk(['file_name' => 'SK_Bupati_2026.pdf', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(5)->addSeconds(3)]);
$mk(['file_name' => 'SKXBupati.pdf', 'started_at' => now()->subMinutes(10)]);
$mk(['tool' => 'merge', 'file_name' => 'Notulen.pdf', 'status' => 'failed', 'error_code' => 'encrypted', 'started_at' => now()->subDays(2)]);
$mk(['tool' => 'ocr', 'file_name' => 'Pindaian KTP.pdf', 'destination' => 'drive', 'drive_path' => 'Drive Saya/Hasil Kisara PDF', 'started_at' => now()->subDays(89)]);
// Penanda: TIDAK boleh muncul di jawaban untuk $A.
$mk(['file_name' => 'PENANDA-KEDALUWARSA-91hari.pdf', 'started_at' => now()->subDays(91)]);
$mk(['nip' => $B, 'file_name' => 'PENANDA-ORANG-LAIN-Gaji.pdf', 'drive_path' => 'PENANDA-JALUR-ORANG-LAIN', 'started_at' => now()->subMinutes(1)]);

$cek('tanpa nip: 422', $get([])->getStatusCode() === 422);
$cek('nip 17 digit: 422', $get(['nip' => '19950614202012100'])->getStatusCode() === 422);
$cek('per_page 51: 422', $get(['nip' => $A, 'per_page' => 51])->getStatusCode() === 422);
$cek('tool di luar K-5: 422', $get(['nip' => $A, 'tool' => 'sign'])->getStatusCode() === 422);

$r = $get(['nip' => $A]);
$j = $body($r);
$raw = $r->getContent();
$cek('200, 4 baris dalam 90 hari, terbaru dulu', $r->getStatusCode() === 200 && count($j['data']) === 4 && $j['data'][0]['file_name'] === 'SK_Bupati_2026.pdf' && $j['data'][3]['tool'] === 'ocr', $raw);
$cek('meta page/per_page/total', $j['meta'] === ['page' => 1, 'per_page' => 50, 'total' => 4], json_encode($j['meta']));
$cek('Cache-Control tidak menyimpan publik', ! str_contains((string) $r->headers->get('Cache-Control'), 'public'));

echo "\n=== kebocoran Resource ===\n";
$izin = ['job_id', 'tool', 'file_name', 'file_count', 'pages', 'bytes_in', 'bytes_out', 'status', 'error_code', 'destination', 'drive_path', 'started_at', 'finished_at'];
$cek('kunci persis daftar-izin di setiap baris', collect($j['data'])->every(fn ($d) => array_keys($d) === $izin), json_encode(array_keys($j['data'][0])));
$cek('penanda pegawai lain tidak ada di badan jawaban', ! str_contains($raw, 'PENANDA-ORANG-LAIN') && ! str_contains($raw, 'PENANDA-JALUR-ORANG-LAIN') && ! str_contains($raw, $B));
$cek('penanda di luar 90 hari tidak ada di badan jawaban', ! str_contains($raw, 'PENANDA-KEDALUWARSA'));
$cek('nip yang ditanyakan tidak diulang di badan jawaban', ! str_contains($raw, $A));
$cek('tidak ada id, created_at, updated_at', ! preg_match('/"(id|created_at|updated_at|nip)"\s*:/', $raw));
$cek('waktu UTC berakhiran Z', $j['data'][0]['started_at'] === Activity::where('file_name', 'SK_Bupati_2026.pdf')->first()->started_at->utc()->format('Y-m-d\TH:i:s\Z') && str_ends_with($j['data'][0]['finished_at'], 'Z') && $j['data'][1]['finished_at'] === null);
$r = $get(['nip' => $B]);
$cek('pegawai lain hanya melihat miliknya (1 baris)', count($body($r)['data']) === 1 && ! str_contains($r->getContent(), 'SK_Bupati'));

echo "\n=== saringan dan cari ===\n";
$n = fn (array $q) => array_column($body($get(array_merge(['nip' => $A], $q)))['data'], 'file_name');
$cek('status=failed', $n(['status' => 'failed']) === ['Notulen.pdf']);
$cek('tool=ocr', $n(['tool' => 'ocr']) === ['Pindaian KTP.pdf']);
$cek('destination=drive', $n(['destination' => 'drive']) === ['Pindaian KTP.pdf']);
$cek('q nama berkas (tanpa beda huruf)', $n(['q' => 'notulen']) === ['Notulen.pdf'], json_encode($n(['q' => 'notulen'])));
$cek('q "SK_" memperlakukan garis bawah harfiah', $n(['q' => 'SK_']) === ['SK_Bupati_2026.pdf'], json_encode($n(['q' => 'SK_'])));
$cek('q "%" tidak menjadi wildcard', $n(['q' => '%']) === []);
$cek('q label alat "gabung" menemukan merge', $n(['q' => 'gabung']) === ['Notulen.pdf']);
$cek('q tidak dapat menembus NIP: penanda orang lain tetap tidak ada', $n(['q' => 'PENANDA']) === []);
$r = $body($get(['nip' => $A, 'per_page' => 2, 'page' => 2]));
$cek('paginasi per_page=2 page=2', count($r['data']) === 2 && $r['meta'] === ['page' => 2, 'per_page' => 2, 'total' => 4] && $r['data'][0]['file_name'] === 'Notulen.pdf', json_encode($r['meta']));

echo "\n=== log teknis ===\n";
$semuaLog = implode("\n", $logs);
$cek('ada log batch (jalur proses tidak diam)', str_contains($semuaLog, 'kisara-pdf activities: batch stored') && str_contains($semuaLog, 'batch rejected'));
$cek('nama berkas tidak masuk log teknis', ! str_contains($semuaLog, 'Pindaian SK') && ! str_contains($semuaLog, 'Laporan Q3') && ! str_contains($semuaLog, 'Keputusan Bupati'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
