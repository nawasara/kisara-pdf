<?php

namespace Nawasara\KisaraPdf\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Nawasara\KisaraPdf\Http\Resources\ActivityResource;
use Nawasara\KisaraPdf\Models\Activity;
use Nawasara\KisaraPdf\Services\ActivityRecorder;
use Nawasara\KisaraPdf\Support\Like;
use Nawasara\KisaraPdf\Support\Tools;

/**
 * Riwayat aktivitas Kisara PDF (kontrak K-4). Pemanggilnya hanya gateway
 * Kisara PDF dengan token layanan, tidak pernah peramban.
 *
 *   POST /api/v1/kisara-pdf/activities   scope: kisara-pdf.activity.write
 *   GET  /api/v1/kisara-pdf/activities   scope: kisara-pdf.activity.read
 *
 * Galat: { "error": { "code", "message", "fields"? } }, sama dengan K-3.
 */
class ActivityController extends Controller
{
    /**
     * Batch ≤ 100 butir, idempoten per job_id.
     *
     * Satu butir tidak sah = seluruh batch 422, tidak ada yang disimpan.
     * `fields` memuat kunci berindeks (`items.3.tool`) supaya outbox gateway
     * dapat menyisihkan butir itu dan mengirim sisanya; menyimpan sebagian
     * diam-diam akan membuat outbox mengira semuanya sudah masuk.
     */
    public function store(Request $request, ActivityRecorder $recorder): JsonResponse
    {
        $max = (int) config('nawasara-kisara-pdf.api.max_batch', 100);
        $future = fn () => now()->utc()->addMinutes((int) config('nawasara-kisara-pdf.api.future_tolerance_minutes', 5));

        $notInFuture = function (string $attribute, mixed $value, \Closure $fail) use ($future) {
            if (! is_string($value)) {
                return;
            }
            try {
                if (Carbon::parse($value)->utc()->gt($future())) {
                    $fail('Waktu tidak boleh lebih dari 5 menit di masa depan.');
                }
            } catch (\Throwable) {
                // Format sudah ditolak oleh aturan "date".
            }
        };

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:'.$max],
            'items.*' => ['required', 'array'],
            'items.*.job_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/'],
            'items.*.nip' => ['required', 'string', 'regex:/^\d{18}$/'],
            'items.*.tool' => ['required', 'string', Rule::in(Tools::names())],
            'items.*.file_name' => ['nullable', 'string', 'max:1000'],
            'items.*.file_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.pages' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.bytes_in' => ['nullable', 'integer', 'min:0'],
            'items.*.bytes_out' => ['nullable', 'integer', 'min:0'],
            'items.*.status' => ['required', 'string', Rule::in(array_keys(Tools::STATUSES))],
            'items.*.error_code' => ['nullable', 'string', 'max:64'],
            'items.*.destination' => ['required', 'string', Rule::in(array_keys(Tools::DESTINATIONS))],
            'items.*.drive_path' => ['nullable', 'string', 'max:2000'],
            'items.*.started_at' => ['required', 'string', 'date', $notInFuture],
            'items.*.finished_at' => ['nullable', 'string', 'date', $notInFuture],
        ]);

        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->input('items', []) as $i => $item) {
                if (! is_array($item) || empty($item['finished_at']) || empty($item['started_at'])) {
                    continue;
                }
                try {
                    if (Carbon::parse($item['finished_at'])->lt(Carbon::parse($item['started_at']))) {
                        $v->errors()->add("items.{$i}.finished_at", 'finished_at lebih awal dari started_at.');
                    }
                } catch (\Throwable) {
                    // Sudah ditolak aturan "date".
                }
            }
        });

        if ($validator->fails()) {
            // Tanpa nilai butir: log teknis tidak boleh memuat nama berkas (NFR-06).
            Log::warning('kisara-pdf activities: batch rejected', [
                'items' => is_array($request->input('items')) ? count($request->input('items')) : null,
                'fields' => array_keys($validator->errors()->toArray()),
            ]);

            return $this->error('validation_failed', 'Ada butir riwayat yang tidak sah; tidak ada yang disimpan.', 422, $validator->errors()->toArray());
        }

        $accepted = $recorder->record(array_values($validator->validated()['items']));
        Log::info('kisara-pdf activities: batch stored', ['accepted' => $accepted]);

        return response()->json(['data' => ['accepted' => $accepted]]);
    }

    /** Riwayat satu pegawai, 90 hari terakhir, terbaru dulu. */
    public function index(Request $request): JsonResponse
    {
        $maxPerPage = (int) config('nawasara-kisara-pdf.api.max_per_page', 50);

        $validator = Validator::make($request->query(), [
            'nip' => ['required', 'string', 'regex:/^\d{18}$/'],
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(array_keys(Tools::STATUSES))],
            'tool' => ['nullable', 'string', Rule::in(Tools::names())],
            'destination' => ['nullable', 'string', Rule::in(array_keys(Tools::DESTINATIONS))],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$maxPerPage],
        ]);

        if ($validator->fails()) {
            return $this->error('validation_failed', 'Parameter tidak sah.', 422, $validator->errors()->toArray());
        }

        $p = $validator->validated();
        $perPage = (int) ($p['per_page'] ?? $maxPerPage);
        $page = (int) ($p['page'] ?? 1);

        $query = Activity::query()
            ->where('nip', $p['nip'])
            ->withinRetention()
            ->when($p['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($p['tool'] ?? null, fn ($q, $v) => $q->where('tool', $v))
            ->when($p['destination'] ?? null, fn ($q, $v) => $q->where('destination', $v));

        $term = trim((string) ($p['q'] ?? ''));
        if ($term !== '') {
            // FR-13: cocok nama berkas atau nama alat.
            $tools = Tools::matching($term);
            $query->where(function ($w) use ($term, $tools) {
                Like::contains($w, 'file_name', $term);
                if ($tools !== []) {
                    $w->orWhereIn('tool', $tools);
                }
            });
        }

        $rows = $query->orderByDesc('started_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => ActivityResource::collection($rows->getCollection())->resolve($request),
            'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    private function error(string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return response()->json(['error' => $error], $status);
    }
}
