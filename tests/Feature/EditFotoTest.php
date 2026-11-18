<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EditFotoTest extends TestCase
{
    use RefreshDatabase;

    private function jpegSementara(): string
    {
        $path = sys_get_temp_dir().'/foto-uji-'.uniqid().'.jpg';
        $gambar = imagecreatetruecolor(8, 8);
        imagejpeg($gambar, $path);

        return $path;
    }

    public function test_employee_edit_foto_menyalin_ke_avatars_dan_dipakai_sebagai_pp(): void
    {
        $e = Employee::factory()->create(['pin_device' => '99924']);
        $sumber = $this->jpegSementara();
        $tujuan = public_path('avatars/pin_99924.jpg');

        try {
            $this->assertSame(0, Artisan::call('employee:edit', ['pin' => '99924', '--foto' => $sumber]));
            $this->assertFileExists($tujuan);
            $this->assertStringEndsWith('avatars/pin_99924.jpg', $e->fresh()->avatarUrl());
        } finally {
            @unlink($tujuan);
            @unlink($sumber);
        }
    }

    public function test_foto_bukan_jpeg_ditolak(): void
    {
        Employee::factory()->create(['pin_device' => '99925']);
        $teks = sys_get_temp_dir().'/bukan-foto-'.uniqid().'.jpg';
        file_put_contents($teks, 'bukan gambar');

        try {
            $this->assertSame(1, Artisan::call('employee:edit', ['pin' => '99925', '--foto' => $teks]));
            $this->assertFileDoesNotExist(public_path('avatars/pin_99925.jpg'));
        } finally {
            @unlink($teks);
        }
    }

    public function test_foto_scan_strip_tidak_dipakai_sebagai_pp(): void
    {
        $e = Employee::factory()->create(['pin_device' => '99926']);
        foreach ([[now()->subDay(), 'https://s3.example/foto.jpg'], [now(), '-']] as [$waktu, $url]) {
            AttendanceLog::create([
                'cloud_id' => 'XXXXX',
                'pin' => '99926',
                'scanned_at' => $waktu,
                'scan_minute' => $waktu->copy()->startOfMinute(),
                'source' => 'webhook',
                'employee_id' => $e->id,
                'photo_url' => $url,
            ]);
        }

        $this->assertSame('https://s3.example/foto.jpg', $e->latestPhotoUrl());
    }
}
