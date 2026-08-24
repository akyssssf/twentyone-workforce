<?php

namespace App\Support;

/**
 * Kata sandi acak yang dibacakan admin ke orangnya saat itu juga.
 *
 * Abjadnya sengaja membuang huruf dan angka yang rancu saat DIBACAKAN, bukan
 * saat dibaca: 0/O dan 1/l/I terdengar sama di telepon dan terlihat sama di
 * layar ponsel murah. Sandi yang salah ketik karena rancu berakhir jadi admin
 * mengulang reset, dan yang mengulang biasanya menyerah lalu memakai sandi
 * gampang.
 *
 * Ada di satu tempat karena dipakai dua jalur: reset sandi dari panel admin dan
 * pembuatan akun lewat perintah artisan.
 */
class SandiAcak
{
    protected const ABJAD = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function buat(int $panjang = 8): string
    {
        $sandi = '';

        for ($i = 0; $i < $panjang; $i++) {
            $sandi .= self::ABJAD[random_int(0, strlen(self::ABJAD) - 1)];
        }

        return $sandi;
    }
}
