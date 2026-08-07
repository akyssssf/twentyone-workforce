<?php

namespace Tests\Unit;

use App\Support\OperationalDate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Hari ini" versi operasional, bukan versi kalender.
 *
 * Shift malam baru kelar jauh setelah tengah malam, jadi "hari ini" tidak
 * boleh pindah tanggal persis jam 00:00 — kalau tidak, jam 2 pagi dashboard
 * menampilkan tanggal baru yang masih kosong sama sekali, padahal yang
 * relevan justru shift malam kemarin yang baru saja kelar.
 */
class OperationalDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sebelum_cutover_masih_tanggal_kemarin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 02:30:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-07', OperationalDate::today()->toDateString());
    }

    public function test_tepat_di_cutover_sudah_pindah(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 06:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-08', OperationalDate::today()->toDateString());
    }

    public function test_satu_menit_sebelum_cutover_masih_kemarin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 05:59:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-07', OperationalDate::today()->toDateString());
    }

    public function test_siang_hari_normal_seperti_biasa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 14:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-08', OperationalDate::today()->toDateString());
    }

    public function test_cutover_bisa_diatur_lewat_config(): void
    {
        config(['attendance.dashboard_cutover_hour' => 8]);
        Carbon::setTestNow(Carbon::parse('2026-08-08 07:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-07', OperationalDate::today()->toDateString());
    }
}
