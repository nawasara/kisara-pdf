# Nawasara Kisara PDF

Bagian Nawasara dari **Kisara PDF** (`pdf.ponorogo.go.id`, alat PDF untuk ASN
Pemkab Ponorogo). Paket ini menyimpan **riwayat aktivitas** yang dikirim
gateway Kisara PDF, menyediakan API riwayat per pegawai untuk halaman Riwayat
aktivitas dan palet cari, halaman audit bagi admin keamanan, dan statistik
pemakaian harian.

Paket ini **tidak** menyimpan berkas. Isi PDF diproses di LXC 146 dan dibuang;
yang sampai ke Nawasara hanya jejak kerja: alat, nama berkas, ukuran, hasil,
dan tujuan. Rencana lengkap ada di `docs/plan/kisara-pdf/` repo nawasara-dev
(FSD FR-11 dan FR-14, kontrak K-4 dan K-5); repo deploy layanannya
`pringgojs/kisara-pdf`.

Bekerja bersama: `nawasara/api` (token layanan + scope, opsional tetapi
diperlukan untuk API), `nawasara/audit` (Log Aktivitas, tempat penelusuran
admin tercatat), `nawasara/sync` (siklus hidup job), `nawasara/ui`.

## Status v0.1.0

| Fitur | Status |
|---|---|
| Tabel `nawasara_kisara_pdf_activities` (bigint, `file_name` text, `job_id` unik, indeks `(nip, started_at)`) | ✅ |
| `POST /api/v1/kisara-pdf/activities`, batch ≤ 100, idempoten per `job_id` (T-18) | ✅ |
| `GET /api/v1/kisara-pdf/activities`, per NIP, 90 hari, cari dan saring, ≤ 50 per halaman (T-18) | ✅ |
| Halaman **Riwayat Pegawai** (audit) dengan pencatatan setiap penelusuran ke Log Aktivitas | ✅ |
| Hapus otomatis riwayat > 90 hari, tiap malam 03.30 WIB | ✅ |
| Statistik harian per alat, tabel `nawasara_kisara_pdf_daily_stats`, dihitung 00.20 WIB, disimpan 2 tahun (T-14) | ✅ |
| Halaman **Statistik**: total, per alat, per hari | ✅ |
| Nama pegawai di halaman audit (sekarang NIP saja) | ⏳ |
| Widget statistik di dashboard Monitoring | ⏳ |

## Setup

```bash
composer require nawasara/kisara-pdf
php artisan migrate
php artisan db:seed --class="Nawasara\KisaraPdf\Database\Seeders\PermissionSeeder"
```

Tambahkan di `resources/css/app.css`, atau semua kelas Tailwind paket ini
tidak ter-compile:

```css
@source "../../vendor/nawasara/kisara-pdf";
```

Lalu buat **satu token layanan** di halaman Token API Nawasara untuk gateway
Kisara PDF, dengan scope `kisara-pdf.activity.write` dan
`kisara-pdf.activity.read`, dan pasangi IP allow-list alamat keluar LXC 146.
Plaintext token disimpan di `.env` LXC 146 sebagai `NAWASARA_ACTIVITY_TOKEN`
(kontrak K-6), tidak di tempat lain.

## API

Basis `/api/v1/kisara-pdf`, middleware `api` + `api.auth` + `api.log`.
`api.log` hanya mencatat jalur tanpa query string dan tanpa badan, jadi NIP
dan nama berkas tidak masuk log akses API.

| Metode | Jalur | Scope | Isi |
|---|---|---|---|
| POST | `/activities` | `kisara-pdf.activity.write` | `{ "items": [ ≤ 100 butir ] }` → `{ "data": { "accepted": n } }` |
| GET | `/activities?nip=&q=&status=&tool=&destination=&page=&per_page=` | `kisara-pdf.activity.read` | `{ "data": [...], "meta": { "page", "per_page", "total" } }` |

Butir POST, juga bentuk tiap baris GET (snake_case; gateway yang menerjemahkan
ke camelCase untuk peramban, K-3):

```json
{ "job_id": "0b8f5c2e-…", "nip": "199001012015011001", "tool": "compress",
  "file_name": "Pindaian SK.pdf", "file_count": 1, "pages": 8,
  "bytes_in": 37121000, "bytes_out": 420000, "status": "success",
  "error_code": null, "destination": "download", "drive_path": null,
  "started_at": "2026-11-20T03:32:10Z", "finished_at": "2026-11-20T03:32:15Z" }
```

Baris GET tidak memuat `nip` (pemanggil sudah tahu), `id`, `created_at`, dan
`updated_at` (Resource daftar-izin, `ActivityResource`).

Validasi POST: `tool` dari daftar K-5 (`Support\Tools`), `nip` 18 digit,
`file_name` ≤ 1000 karakter, `status` `success|failed`, `destination`
`download|drive|none`, waktu ISO 8601 tidak lebih dari 5 menit di masa depan,
`finished_at` tidak sebelum `started_at`.

Galat: `{ "error": { "code": "validation_failed", "message": "…", "fields": { "items.3.tool": ["…"] } } }`, 422.

## Model

| Model | Tabel | Isi |
|---|---|---|
| `Activity` | `nawasara_kisara_pdf_activities` | satu pekerjaan; umur 90 hari |
| `DailyStat` | `nawasara_kisara_pdf_daily_stats` | per hari WIB per alat: `jobs`, `failed`, `avg_ms`, `bytes_in`; umur 2 tahun |

## Permissions

| Permission | Untuk |
|---|---|
| `kisara-pdf.activity.audit` | Halaman Riwayat Pegawai: riwayat semua pegawai, termasuk nama berkas. Hanya admin keamanan Diskominfo |
| `kisara-pdf.stats.view` | Halaman Statistik. Tanpa data orang, boleh dibagikan lebih luas |

## Jadwal

| Nama | Waktu | Isi |
|---|---|---|
| `nawasara-kisara-pdf:aggregate-daily-stats` | 00.20 WIB | hitung ulang statistik 3 hari terakhir |
| `nawasara-kisara-pdf:prune-activities` | 03.30 WIB | hapus riwayat > 90 hari dan statistik > 2 tahun |

Keduanya `$schedule->call()` (AGENTS.md §7), tercatat di Riwayat Sinkronisasi
`nawasara/sync` dengan layanan `kisara-pdf`.

## Keputusan yang jangan dibatalkan

- **Nama berkas disimpan apa adanya (BRD A-05).** Nama berkas dinas kadang
  memuat nama orang atau NIP ("SK Pensiun Siti Aminah.pdf"). Menyamarkannya
  akan membuat riwayat tidak berguna bagi pemiliknya sendiri, padahal tujuan
  riwayat adalah menemukan kembali pekerjaan sendiri. Yang menjaga data ini
  adalah aksesnya: pegawai hanya melihat miliknya lewat gateway, admin hanya
  dengan `kisara-pdf.activity.audit`, setiap penelusuran admin tercatat, dan
  barisnya terhapus setelah 90 hari. Ini sama dengan Jejak Akses Workspace.
  Karena itu pula nama berkas **tidak** ikut ke log teknis, ke log akses API,
  maupun ke catatan audit penelusuran: di sana ia akan hidup lebih lama dari
  90 hari.
- **Penelusuran dicatat per kumpulan baris yang berbeda**, termasuk saat
  halaman dibuka tanpa saringan dan saat pindah halaman: itu pun sudah
  memperlihatkan nama berkas orang lain. Sidik saringan disimpan di properti
  `#[Locked]`; bila peramban dapat mengisinya, ia dapat membuat penelusuran
  berikutnya tidak tercatat. Kotak cari memakai debounce 700 md supaya yang
  tercatat kata kunci yang selesai diketik, bukan tiap huruf.
- **Kiriman ulang `job_id` tidak mengubah `nip`.** Outbox gateway mengirim
  ulang dengan bebas dan Simpan ke Drive mengirim ulang untuk mengganti
  `destination`, tetapi satu pekerjaan milik satu pegawai sejak awal. Bila
  NIP ikut diperbarui, satu galat di gateway memindahkan riwayat seseorang ke
  riwayat orang lain tanpa jejak.
- **Satu butir salah menolak seluruh batch.** Menyimpan sebagian diam-diam
  membuat outbox mengira semuanya sudah masuk. Kunci galat berindeks
  (`items.3.tool`) memberi tahu gateway butir mana yang harus disisihkan.
- **Statistik dihitung ulang per hari, bukan ditambah.** Catatan dapat tiba
  berhari-hari terlambat dari outbox, dan butir yang sama dapat dikirim dua
  kali. Menghapus lalu menghitung ulang hari itu dari sumber membuat keduanya
  benar; karena itu yang dihitung ulang 3 hari terakhir, bukan hanya kemarin.
  Hari = hari kalender WIB.
- **`daily_stats` tidak boleh mendapat kolom NIP atau nama berkas.** Tabel itu
  hidup 2 tahun, jauh melewati 90 hari riwayat; satu kolom tambahan di sana
  meniadakan hapus otomatis riwayat.
- **Angka hari di bawah 30 ditolak prune**, bukan dijalankan: salah ketik env
  `NAWASARA_KISARA_PDF_RETENTION_DAYS=9` (maksudnya 90) tidak boleh berubah
  menjadi "hapus hampir semuanya".
- **LIKE dengan ESCAPE eksplisit.** Nama berkas sering memuat garis bawah
  (`SK_Bupati_2026.pdf`); tanpa escape, `_` menjadi wildcard dan pencarian
  melebar diam-diam. `ESCAPE '!'` ditulis eksplisit karena bawaan MySQL dan
  SQLite berbeda.
- **Workspace menu sendiri (`kisara-pdf`), bukan submenu `kisara-workspace`.**
  WorkspaceManager mengambil label dan ikon workspace dari paket yang dimuat
  pertama menurut abjad, dan `kisara-pdf` mendahului `workspace`: menumpang
  berarti menyalin label "Kisara Workspace" ke paket ini. Kisara PDF juga
  layanan terpisah (server, klien Keycloak, admin berbeda).

## Uji

Skrip uji manual (SQLite di memori, permintaan HTTP sungguhan lewat
`api.auth` dan Livewire sungguhan), dijalankan di VM remote-test:

```bash
bash /d/claude-for-work/claude-code-skills/tooling/remote-test.sh -- \
  'composer update nawasara/kisara-pdf --no-interaction --no-progress && php packages/nawasara-kisara-pdf/tests/manual/uji-api-riwayat.php'
bash /d/claude-for-work/claude-code-skills/tooling/remote-test.sh -- \
  'composer update nawasara/kisara-pdf --no-interaction --no-progress && php packages/nawasara-kisara-pdf/tests/manual/uji-audit-statistik.php'
```

`uji-api-riwayat.php` memuat uji kebocoran Resource dengan baris berpenanda
milik pegawai lain dan di luar 90 hari.

## Roadmap

- Nama pegawai di halaman audit (dari cache Keycloak), tanpa mengubah API.
- Widget statistik di dashboard Monitoring.
- Batas laju per token untuk POST bila gateway lebih dari satu.

## Author

Pringgo J. Saputro, Diskominfo Kabupaten Ponorogo.

## License

MIT
