<?php

namespace Nawasara\KisaraPdf\Support;

/**
 * Nama alat dari kontrak K-5. Gateway menentukan `tool` dari jalur API
 * Stirling; Nawasara hanya menerima nama di daftar ini, dan labelnya dipakai
 * di halaman audit, statistik, dan pencarian riwayat (FR-13: "cocok nama alat").
 */
final class Tools
{
    public const LABELS = [
        'compress' => 'Kompres',
        'merge' => 'Gabung',
        'split' => 'Pisah',
        'rotate' => 'Putar',
        'reorder' => 'Susun ulang',
        'remove-pages' => 'Hapus halaman',
        'ocr' => 'OCR',
        'office-to-pdf' => 'Word/Excel/PPT ke PDF',
        'img-to-pdf' => 'Gambar ke PDF',
        'pdf-to-word' => 'PDF ke Word',
        'pdf-to-img' => 'PDF ke gambar',
        'pdf-to-text' => 'PDF ke teks',
        // FR-17 (kontrak K-5 v1.4): konversi per format dari beranda.
        'pdf-to-ppt' => 'PDF ke PowerPoint',
        'pdf-to-excel' => 'PDF ke Excel',
        'pdf-to-pdfa' => 'PDF ke PDF/A',
        'add-password' => 'Kunci sandi',
        'remove-password' => 'Buka sandi',
        'watermark' => 'Watermark',
        'redact' => 'Sensor teks',
        'sanitize' => 'Bersihkan',
        'page-numbers' => 'Nomor halaman',
    ];

    public const STATUSES = ['success' => 'Berhasil', 'failed' => 'Gagal'];

    public const STATUS_COLORS = ['success' => 'success', 'failed' => 'danger'];

    public const DESTINATIONS = ['download' => 'Diunduh', 'drive' => 'Kisara Drive', 'none' => 'Tidak ada'];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $tool): string
    {
        return self::LABELS[$tool] ?? $tool;
    }

    /**
     * Alat yang nama atau labelnya memuat $term (tanpa membedakan huruf).
     *
     * @return list<string>
     */
    public static function matching(string $term): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return [];
        }

        $hits = [];
        foreach (self::LABELS as $name => $label) {
            if (str_contains($name, $term) || str_contains(mb_strtolower($label), $term)) {
                $hits[] = $name;
            }
        }

        return $hits;
    }
}
