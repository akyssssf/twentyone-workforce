<?php

namespace App\Models\Concerns;

/**
 * Menjaga shift_key selalu sama dengan shift_id (0 kalau null).
 *
 * Kolom bantu ini ada karena double shift diizinkan, sehingga
 * (employee_id, work_date) tidak lagi cukup jadi kunci unik - dan karena semua
 * engine memperlakukan NULL sebagai selalu-berbeda di unique index, tanpa
 * kolom ini satu karyawan bisa punya banyak baris libur di tanggal yang sama.
 *
 * Dipakai kolom biasa alih-alih generated column karena SQLite tidak bisa
 * menambahkan generated column bertipe STORED ke tabel yang sudah berisi data,
 * yang persis situasi tabel attendances.
 */
trait HasShiftKey
{
    protected static function bootHasShiftKey(): void
    {
        static::saving(function ($model) {
            $model->shift_key = (int) ($model->shift_id ?? 0);
        });
    }
}
