<?php

namespace Tests\Feature;

use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AddShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_tambah_membuat_shift_baru_tidak_melewati_tengah_malam(): void
    {
        $this->assertSame(0, Artisan::call('shift:tambah', ['kode' => 'manager', 'nama' => 'Shift Manager', 'mulai' => '08:00', 'selesai' => '23:30']));

        $s = Shift::where('code', 'manager')->first();
        $this->assertSame('08:00:00', $s->start_time);
        $this->assertSame('23:30:00', $s->end_time);
        $this->assertFalse($s->crosses_midnight);
    }

    public function test_kode_kembar_dan_jam_salah_ditolak(): void
    {
        Artisan::call('shift:tambah', ['kode' => 'manager', 'nama' => 'A', 'mulai' => '08:00', 'selesai' => '23:30']);

        $this->assertSame(1, Artisan::call('shift:tambah', ['kode' => 'manager', 'nama' => 'B', 'mulai' => '08:00', 'selesai' => '23:30']));
        $this->assertSame(1, Artisan::call('shift:tambah', ['kode' => 'lain', 'nama' => 'C', 'mulai' => '8am', 'selesai' => '23:30']));
    }
}
