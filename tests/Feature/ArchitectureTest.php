<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Batas antar modul yang dijaga otomatis.
 *
 * Aturan arsitektur yang cuma ditulis di dokumen akan dilanggar suatu saat —
 * biasanya oleh orang yang sedang buru-buru dan tidak tahu aturannya ada.
 * Uji ini membuat pelanggarannya berhenti di CI, bukan di gajian.
 */
class ArchitectureTest extends TestCase
{
    /**
     * Isi berkas TANPA komentar.
     *
     * Perlu, karena dokumentasi di kelas-kelas ini justru menyebut nama tabel
     * yang dilarang untuk menjelaskan larangannya. Kalau komentar ikut
     * diperiksa, uji ini gagal justru pada berkas yang paling patuh.
     */
    protected function kodeTanpaKomentar(string $file): string
    {
        $kode = '';

        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kode .= is_array($token) ? $token[1] : $token;
        }

        return $kode;
    }

    /**
     * BR-02: payroll tidak boleh membaca data fingerprint mentah.
     *
     * Larangan ini ditegakkan secara struktural, bukan lewat niat baik: modul
     * Payroll tidak boleh menyebut tabel scan mentah sama sekali. Kalau suatu
     * saat ada yang "sekadar mengintip" ke sana untuk menambal data yang
     * kurang, hasil perhitungan gaji berhenti bisa ditelusuri dari Final
     * Attendance — dan seluruh jaminan payroll lock ikut runtuh.
     */
    public function test_payroll_tidak_menyentuh_scan_mentah(): void
    {
        $terlarang = ['AttendanceLog', 'attendance_logs', 'device_callbacks', 'DeviceCallback'];

        foreach (glob(app_path('Services/Payroll/*.php')) as $file) {
            $isi = $this->kodeTanpaKomentar($file);

            foreach ($terlarang as $kata) {
                $this->assertStringNotContainsString(
                    $kata,
                    $isi,
                    basename($file).' menyentuh scan mentah. Payroll hanya boleh membaca Final Attendance (BR-02).',
                );
            }
        }
    }

    /** Modul absensi mencatat fakta. Rupiah adalah urusan payroll. */
    public function test_modul_absensi_tidak_menghitung_uang(): void
    {
        $terlarang = ['deduction_amount', 'take_home_pay', 'baseSalaryOn', 'RuleResolver'];

        foreach (glob(app_path('Services/Attendance/*.php')) as $file) {
            $isi = $this->kodeTanpaKomentar($file);

            foreach ($terlarang as $kata) {
                $this->assertStringNotContainsString(
                    $kata,
                    $isi,
                    basename($file).' menghitung uang. Absensi mencatat menit; rupiah dihitung modul Payroll.',
                );
            }
        }
    }

    /** Service tidak boleh bergantung pada HTTP request. */
    public function test_service_tidak_bergantung_pada_http(): void
    {
        foreach (glob(app_path('Services/*/*.php')) as $file) {
            $isi = $this->kodeTanpaKomentar($file);

            $this->assertStringNotContainsString(
                'Illuminate\Http\Request',
                $isi,
                basename($file).' bergantung pada HTTP request. Service harus bisa dipanggil dari CLI dan API juga.',
            );
        }
    }
}
