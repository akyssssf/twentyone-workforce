<?php

namespace Tests\Unit;

use App\Support\Durasi;
use PHPUnit\Framework\TestCase;

class DurasiTest extends TestCase
{
    public function test_menit_di_bawah_sejam_ditulis_apa_adanya(): void
    {
        $this->assertSame('18m', Durasi::menit(18));
        $this->assertSame('59m', Durasi::menit(59));
    }

    /**
     * Inti perbaikannya: "354 m" memaksa pembacanya membagi 60 di kepala.
     */
    public function test_menit_lewat_sejam_dipecah_jadi_jam_dan_menit(): void
    {
        $this->assertSame('1j', Durasi::menit(60));
        $this->assertSame('3j 18m', Durasi::menit(198));
        $this->assertSame('5j 54m', Durasi::menit(354));
    }

    public function test_jam_bulat_tidak_menyeret_nol_menit(): void
    {
        $this->assertSame('2j', Durasi::menit(120));
        $this->assertSame('2j 1m', Durasi::menit(121));
    }

    public function test_kosong_dan_negatif_jadi_tanda_pisah(): void
    {
        $this->assertSame(Durasi::KOSONG, Durasi::menit(0));
        $this->assertSame(Durasi::KOSONG, Durasi::menit(-5));
        $this->assertSame(Durasi::KOSONG, Durasi::detik(0));
    }

    /**
     * Detik cuma ditampilkan selama durasinya belum sampai satu menit. Di atas
     * itu detik tidak menambah apa pun selain kebisingan.
     */
    public function test_detik_hanya_muncul_di_bawah_semenit(): void
    {
        $this->assertSame('45d', Durasi::detik(45));
        $this->assertSame('7m', Durasi::detik(420));
        $this->assertSame('1j 10m', Durasi::detik(4200));
    }
}
