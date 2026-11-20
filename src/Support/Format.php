<?php

namespace Nawasara\KisaraPdf\Support;

/** Angka untuk tampilan admin (Bahasa Indonesia: koma desimal). */
final class Format
{
    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }

    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '-';
        }
        if ($ms < 1000) {
            return $ms.' md';
        }
        if ($ms < 60000) {
            return number_format($ms / 1000, 1, ',', '.').' dtk';
        }

        return intdiv($ms, 60000).' mnt '.intdiv($ms % 60000, 1000).' dtk';
    }

    public static function number(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
