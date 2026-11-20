<?php

namespace Nawasara\KisaraPdf\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * LIKE "memuat" yang memperlakukan % dan _ dari pengguna sebagai huruf biasa.
 *
 * Nama berkas dinas sering memuat garis bawah ("SK_Bupati_2026.pdf"); tanpa
 * escape, "_" menjadi wildcard satu karakter dan hasilnya melebar diam-diam.
 * ESCAPE ditulis eksplisit karena MySQL dan SQLite berbeda bawaannya.
 */
final class Like
{
    public static function contains(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", ['%'.$escaped.'%'], $boolean);
    }
}
