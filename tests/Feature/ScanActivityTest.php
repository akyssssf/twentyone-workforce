<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScanActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function scan(string $pin, string $waktu, string $source = 'sync', ?string $photo = null): AttendanceLog
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        return AttendanceLog::create([
            'cloud_id' => 'GQ5179086',
            'pin' => $pin,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'verify_mode' => 4,
            'status_scan' => 0,
            'photo_url' => $photo,
            'source' => $source,
        ]);
    }

    protected function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_tamu_tidak_bisa_membuka_aktivitas(): void
    {
        $this->get('/aktivitas')->assertRedirect(route('login'));
    }

    public function test_menampilkan_scan_terbaru_di_atas(): void
    {
        $this->scan('1', '2026-08-06 11:15:21');
        $this->scan('1', '2026-08-06 11:43:08');

        $response = $this->actingAs($this->owner())->get('/aktivitas')->assertOk();

        $urutan = $response->viewData('scan')->pluck('scanned_at')
            ->map(fn ($t) => $t->format('H:i:s'))->all();

        $this->assertSame(['11:43:08', '11:15:21'], $urutan);
    }

    public function test_ringkasan_memisahkan_sumber(): void
    {
        $this->scan('1', '2026-08-06 09:00:00', 'webhook');
        $this->scan('1', '2026-08-06 10:00:00', 'sync');
        $this->scan('1', '2026-08-06 11:00:00', 'sync');

        $ringkasan = $this->actingAs($this->owner())->get('/aktivitas')->viewData('ringkasan');

        $this->assertSame(3, $ringkasan['total']);
        $this->assertSame(1, $ringkasan['webhook']);
        $this->assertSame(2, $ringkasan['sync']);
    }

    public function test_menandai_pin_yang_belum_terdaftar(): void
    {
        $this->scan('1', '2026-08-06 11:15:21');

        $this->actingAs($this->owner())->get('/aktivitas')
            ->assertOk()
            ->assertSee('PIN belum terdaftar')
            ->assertSee('belum terdaftar');
    }

    public function test_menampilkan_nama_kalau_pin_sudah_terdaftar(): void
    {
        Employee::factory()->create([
            'pin_device' => '1', 'name' => 'bos amal',
            'default_shift_id' => Shift::factory()->create()->id,
        ]);
        $this->scan('1', '2026-08-06 11:15:21');

        $this->actingAs($this->owner())->get('/aktivitas')
            ->assertOk()
            ->assertSee('bos amal')
            ->assertDontSee('PIN belum terdaftar');
    }

    public function test_foto_ditampilkan_kalau_ada(): void
    {
        $this->scan('1', '2026-08-06 11:15:21', 'sync', 'https://s3.example.com/foto/abc.jpg');

        $this->actingAs($this->owner())->get('/aktivitas')
            ->assertOk()
            ->assertSee('https://s3.example.com/foto/abc.jpg');
    }

    /**
     * Halaman ini gampang disalahpahami sebagai cerminan penuh dashboard
     * mesin, padahal kegagalan autentikasi tidak pernah sampai ke kita.
     */
    public function test_menjelaskan_kenapa_scan_gagal_tidak_muncul(): void
    {
        $this->actingAs($this->owner())->get('/aktivitas')
            ->assertOk()
            ->assertSee('Authentication via Face Failed');
    }

    // ------------------------------------------------------------- penyaring

    public function test_saring_berdasarkan_pin(): void
    {
        $this->scan('1', '2026-08-06 09:00:00');
        $this->scan('2', '2026-08-06 10:00:00');

        $scan = $this->actingAs($this->owner())->get('/aktivitas?pin=2')->viewData('scan');

        $this->assertCount(1, $scan);
        $this->assertSame('2', $scan->first()->pin);
    }

    public function test_saring_berdasarkan_sumber(): void
    {
        $this->scan('1', '2026-08-06 09:00:00', 'webhook');
        $this->scan('1', '2026-08-06 10:00:00', 'sync');

        $scan = $this->actingAs($this->owner())->get('/aktivitas?sumber=webhook')->viewData('scan');

        $this->assertCount(1, $scan);
        $this->assertSame('webhook', $scan->first()->source);
    }

    public function test_saring_berdasarkan_rentang_tanggal(): void
    {
        $this->scan('1', '2026-08-04 09:00:00');
        $this->scan('1', '2026-08-06 09:00:00');
        $this->scan('1', '2026-08-08 09:00:00');

        $scan = $this->actingAs($this->owner())
            ->get('/aktivitas?dari=2026-08-05&sampai=2026-08-07')
            ->viewData('scan');

        $this->assertCount(1, $scan);
        $this->assertSame('2026-08-06', $scan->first()->scanned_at->toDateString());
    }

    /**
     * Batas akhir harus mencakup seluruh hari, bukan berhenti di 00:00.
     */
    public function test_batas_sampai_mencakup_sampai_akhir_hari(): void
    {
        $this->scan('1', '2026-08-06 23:59:00');

        $scan = $this->actingAs($this->owner())
            ->get('/aktivitas?dari=2026-08-06&sampai=2026-08-06')
            ->viewData('scan');

        $this->assertCount(1, $scan);
    }

    /**
     * Carbon::createFromFormat menerima "13-45-99" lalu menggulungnya diam-diam
     * jadi tahun 0016, dan tanggal gulungan itu menyaring habis seluruh data
     * sehingga terlihat persis seperti "datanya hilang".
     *
     * @param  string  $dari
     * @param  string  $sampai
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tanggalTidakSah')]
    public function test_tanggal_ngawur_diabaikan_bukan_menyaring_habis(string $dari, string $sampai): void
    {
        $this->scan('1', '2026-08-06 09:00:00');

        $scan = $this->actingAs($this->owner())
            ->get("/aktivitas?dari={$dari}&sampai={$sampai}")
            ->assertOk()
            ->viewData('scan');

        // Penyaring yang tidak sah harus diabaikan, bukan menghapus hasil.
        $this->assertCount(1, $scan);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tanggalTidakSah(): array
    {
        return [
            'bukan tanggal' => ['bukan-tanggal', 'juga-bukan'],
            'angka digulung' => ['', '13-45-99'],
            'tanggal tidak ada di kalender' => ['', '2026-02-31'],
            'format terbalik' => ['06-08-2026', ''],
        ];
    }

    public function test_pagination_jalan_dan_filter_ikut_terbawa(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->scan('1', Carbon::parse('2026-08-06 08:00:00')->addMinutes($i)->toDateTimeString());
        }

        $halaman1 = $this->actingAs($this->owner())->get('/aktivitas?pin=1')->viewData('scan');

        $this->assertCount(50, $halaman1);
        $this->assertSame(60, $halaman1->total());
        // Filter harus ikut di tautan halaman berikutnya.
        $this->assertStringContainsString('pin=1', $halaman1->nextPageUrl());
    }
}
