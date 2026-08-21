<?php

namespace App\Services\Fingerspot;

/**
 * Penyusun isi field `template` untuk pendaftaran wajah (Seri VIDA/VIVO).
 *
 * Bentuknya BASE64 GANDA, dan ini memang aneh sampai perlu ditulis: foto JPEG
 * di-base64, dibungkus JSON `{"face":"<base64-jpeg>"}`, lalu SELURUH string JSON
 * itu di-base64 sekali lagi. Salah satu lapis saja terlewat, mesin menolaknya —
 * dan penolakannya datang belakangan lewat webhook, bukan sebagai error saat
 * dikirim. Ditaruh di kelas sendiri supaya keanehan ini punya satu tempat dan
 * satu tes, bukan disalin ke mana-mana.
 *
 * Batas ukuran ditegakkan di sini, bukan dipercayakan ke pemanggil: foto 150 KB
 * tetap terkirim mulus dan baru gagal di mesin, jadi menolaknya lebih awal
 * dengan pesan jelas adalah satu-satunya cara kegagalannya terlihat.
 */
class FaceTemplate
{
    /** Batas dari dokumentasi Fingerspot: foto wajah maksimal 100 KB. */
    public const MAX_BYTES = 100 * 1024;

    /** Tiga byte pembuka setiap berkas JPEG. */
    protected const JPEG_MAGIC = "\xFF\xD8\xFF";

    public static function dariJpeg(string $isi): string
    {
        self::pastikanJpeg($isi);
        self::pastikanMuat($isi);

        $json = json_encode(['face' => base64_encode($isi)]);

        if ($json === false) {
            throw new FingerspotException('Gagal menyusun JSON template wajah.');
        }

        return base64_encode($json);
    }

    public static function dariBerkas(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new FingerspotException("Berkas foto tidak ada atau tidak bisa dibaca: {$path}");
        }

        $isi = file_get_contents($path);

        if ($isi === false || $isi === '') {
            throw new FingerspotException("Berkas foto kosong: {$path}");
        }

        return self::dariJpeg($isi);
    }

    protected static function pastikanJpeg(string $isi): void
    {
        // Diperiksa dari byte pembukanya, bukan dari akhiran nama berkas —
        // PNG yang diganti namanya jadi .jpg akan lolos kalau cuma melihat nama,
        // lalu gagal di mesin tanpa keterangan apa pun.
        if (! str_starts_with($isi, self::JPEG_MAGIC)) {
            throw new FingerspotException(
                'Foto harus JPEG. Berkas ini bukan JPEG (dilihat dari isinya, bukan namanya).'
            );
        }
    }

    protected static function pastikanMuat(string $isi): void
    {
        $ukuran = strlen($isi);

        if ($ukuran > self::MAX_BYTES) {
            throw new FingerspotException(sprintf(
                'Foto %s KB melebihi batas %s KB. Perkecil dulu, mis. dengan resolusi lebih rendah atau kualitas JPEG 70%%.',
                number_format($ukuran / 1024, 1),
                number_format(self::MAX_BYTES / 1024, 0),
            ));
        }
    }
}
