<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\RuleSet;
use App\Models\SalaryComponent;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payroll\KasbonService;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodFactory;
use App\Services\Roster\RosterService;
use App\Support\Settings;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Slip gaji: hitung ulang, toleransi telat, rumus lembur, kasbon, rincian.
 *
 * Periode uji: 2026-09 = 21 Agustus s/d 20 September 2026, gaji pokok
 * Rp 3.000.000, 26 hari kerja di roster.
 *   tarif harian  = 3.000.000 ÷ 26 = 115.384
 *   tarif per jam = 115.384 ÷ 10  = 11.538
 */
class SlipGajiTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $budi;

    protected PayrollPeriod $periode;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->pagi->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->budi = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Budi Uji',
            'pin_device' => '31',
            'default_shift_id' => $this->pagi->id,
        ]);

        $this->budi->salaries()->create([
            'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
            'amount' => 3_000_000,
            'effective_from' => '2026-08-01',
        ]);

        $this->periode = app(PayrollPeriodFactory::class)->forMonth(2026, 9);

        // 26 hari kerja di roster: 21 Agustus s/d 15 September.
        $service = app(RosterService::class);
        $tanggal = Carbon::parse('2026-08-21');

        for ($i = 0; $i < 26; $i++) {
            $hari = $tanggal->copy()->addDays($i);
            $service->assign($service->findOrCreate((int) $hari->year, (int) $hari->month), $this->budi, $hari, $this->pagi->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function hadir(string $tanggal, int $telatDetik = 0, int $pulangCepatMenit = 0, AttendanceStatus $status = AttendanceStatus::Hadir): Attendance
    {
        $toleransi = Settings::int('attendance.late_tolerance_minutes');

        return Attendance::create([
            'employee_id' => $this->budi->id,
            'shift_id' => $this->pagi->id,
            'work_date' => Carbon::parse($tanggal),
            'scheduled_in' => Carbon::parse("{$tanggal} 08:00:00", 'Asia/Jakarta'),
            'scheduled_out' => Carbon::parse("{$tanggal} 18:00:00", 'Asia/Jakarta'),
            'check_in_at' => Carbon::parse("{$tanggal} 08:00:00", 'Asia/Jakarta')->addSeconds($telatDetik),
            'check_out_at' => Carbon::parse("{$tanggal} 18:00:00", 'Asia/Jakarta')->subMinutes($pulangCepatMenit),
            'late_seconds' => $telatDetik,
            // Seperti AttendanceComputer: menit nol di dalam toleransi.
            'late_minutes' => $telatDetik > $toleransi * 60 ? (int) ceil($telatDetik / 60) : 0,
            'early_leave_seconds' => $pulangCepatMenit * 60,
            'early_leave_minutes' => $pulangCepatMenit,
            'status' => $status,
            'computed_at' => now(),
        ]);
    }

    protected function hitung(): Payslip
    {
        $run = app(PayrollGenerator::class)->generate($this->periode->fresh());

        return $run->payslips()->where('employee_id', $this->budi->id)->with('items')->firstOrFail();
    }

    protected function item(Payslip $slip, string $sourceType)
    {
        return $slip->items->firstWhere('source_type', $sourceType);
    }

    /**
     * Bug produksi: "Hitung ulang" selalu gagal dengan UNIQUE constraint
     * failed: payslips.code, karena nomor slip unik se-tabel padahal tiap
     * run membuat baris slip baru dengan nomor yang sama.
     */
    public function test_hitung_ulang_tidak_menabrak_nomor_slip(): void
    {
        $pertama = $this->hitung();
        $kedua = $this->hitung();

        $this->assertSame('SLIP-2026-09-'.sprintf('%03d', $this->budi->id), $pertama->code);
        $this->assertSame($pertama->code, $kedua->code, 'nomor slip harus tetap sama antar versi');
        $this->assertNotSame($pertama->id, $kedua->id);

        $this->assertSame(2, (int) $kedua->run->version);
        $this->assertSame('superseded', $pertama->run->fresh()->status);
        $this->assertSame('completed', $kedua->run->status);
    }

    /**
     * Toleransi 10 menit: datang 08:07 tidak dipotong dan tidak dihitung
     * telat, tapi tetap muncul di slip sebagai "dalam toleransi". Datang
     * 08:11 dipotong PENUH 11 menit — toleransi itu ambang, bukan diskon.
     */
    public function test_toleransi_telat_tidak_dipotong_tapi_tercatat_di_slip(): void
    {
        $this->assertSame(10, Settings::int('attendance.late_tolerance_minutes'));

        $this->hadir('2026-08-21', telatDetik: 7 * 60);
        $this->hadir('2026-08-22', telatDetik: 10 * 60);
        $this->hadir('2026-08-24', telatDetik: 11 * 60);

        $slip = $this->hitung();

        $this->assertSame(1, (int) $slip->late_count, 'cuma yang lewat toleransi yang dihitung telat');

        $potongan = $this->item($slip, 'late');
        $this->assertNotNull($potongan);
        $this->assertSame('Potongan Terlambat (1x)', $potongan->label);
        // Rp 1.000 per menit, SELURUH 11 menit dihitung (bukan cuma 1 lewat toleransi).
        $this->assertSame(11_000, (int) $potongan->amount);
        $this->assertSame([['date' => '2026-08-24', 'minutes' => 11, 'amount' => 11_000, 'rule' => 'Rp1.000 per menit keterlambatan']], $potongan->rule_snapshot['rincian']);

        $info = $slip->items->where('category', 'info')->first(fn ($i) => str_contains($i->label, 'Toleransi telat 10 menit'));
        $this->assertNotNull($info);
        $this->assertStringContainsString('2x datang lewat masih dalam toleransi', $info->label);
        $this->assertSame(['2026-08-21', '2026-08-22'], array_column($info->rule_snapshot['rincian'], 'date'));

        // Baris info tidak ikut mengurangi gaji, dan tidak ada BPJS.
        $this->assertSame(3_000_000, (int) $slip->total_earning);
        $this->assertSame(11_000, (int) $slip->total_deduction);
        $this->assertSame(0, (int) $slip->total_statutory, 'kafe tidak memotong BPJS');
        $this->assertSame(2_989_000, (int) $slip->take_home_pay);
    }

    /**
     * Rumus pemilik: tarif per jam = gaji pokok ÷ hari kerja ÷ 10, lembur
     * dibayar 1× tarif itu untuk semua jam.
     */
    public function test_bonus_lembur_memakai_tarif_gaji_dibagi_hari_dibagi_sepuluh(): void
    {
        $this->hadir('2026-09-05');
        $this->hadir('2026-09-12');

        foreach (['2026-09-05' => 120, '2026-09-12' => 90] as $tanggal => $menit) {
            OvertimeRecord::create([
                'employee_id' => $this->budi->id,
                'work_date' => Carbon::parse($tanggal),
                'actual_minutes' => $menit,
                'approved_minutes' => $menit,
                'payable_minutes' => $menit,
                'status' => 'confirmed',
                'activated_at' => now(),
                'confirmed_at' => now(),
            ]);
        }

        $slip = $this->hitung();

        $tarifJam = intdiv(intdiv(3_000_000, 26), 10);
        $this->assertSame(11_538, $tarifJam);

        $lembur = $this->item($slip, 'overtime');
        $this->assertNotNull($lembur);
        $this->assertSame('Bonus Lembur 3 jam 30 menit', $lembur->label);
        $this->assertSame($tarifJam, (int) $lembur->rate);
        $this->assertSame(2 * $tarifJam + (int) round(1.5 * $tarifJam), (int) $lembur->amount);
        $this->assertSame(210, (int) $slip->overtime_minutes);

        $rincian = $lembur->rule_snapshot['rincian'];
        $this->assertCount(2, $rincian);
        $this->assertSame('2026-09-05', $rincian[0]['date']);
        $this->assertSame(120, $rincian[0]['minutes']);
        $this->assertSame(2 * $tarifJam, $rincian[0]['amount']);

        // Dasar perhitungan tercetak di slip supaya bisa dicek dengan kalkulator.
        $basis = $slip->items->where('category', 'info')->pluck('rate', 'label');
        $this->assertSame(115_384, (int) $basis['Tarif harian: gaji pokok ÷ 26 hari']);
        $this->assertSame($tarifJam, (int) $basis['Tarif per jam: tarif harian ÷ 10 jam']);
    }

    /**
     * calc_type per_block (ditambahkan langsung di server): rupiah per blok
     * 10 menit, dibulatkan ke atas. Harus dikenali, bukan jatuh ke nol.
     */
    public function test_tier_per_blok_sepuluh_menit_dikenali(): void
    {
        $late = RuleSet::where('type', 'late')->firstOrFail();
        $late->tiers()->delete();
        $late->tiers()->create(['min_value' => 1, 'max_value' => null, 'unit' => 'minute', 'calc_type' => 'per_block', 'value' => 5000, 'label' => 'Per 10 menit', 'sort_order' => 1]);

        $this->hadir('2026-08-24', telatDetik: 25 * 60);

        $slip = $this->hitung();

        // 25 menit = 3 blok × Rp 5.000.
        $this->assertSame(15_000, (int) $this->item($slip, 'late')->amount);
    }

    /**
     * Periode 2026-09 sudah dibayar dengan slip luar: alpha tidak dipotong,
     * lembur di luar THP. Di sistem itu dicapai lewat tanggal berlaku aturan
     * (mulai 21 Sep), bukan kode khusus — aturan yang belum berlaku berarti
     * tidak ada potongan/bonus, dan lembur tetap tercatat sebagai keterangan.
     */
    public function test_aturan_yang_belum_berlaku_tidak_memotong_dan_lembur_jadi_keterangan(): void
    {
        RuleSet::whereIn('type', ['absent', 'overtime'])->update(['effective_from' => '2026-09-21']);

        $this->hadir('2026-08-27', status: AttendanceStatus::Alpha);
        $this->hadir('2026-09-05');
        OvertimeRecord::create([
            'employee_id' => $this->budi->id, 'work_date' => Carbon::parse('2026-09-05'),
            'actual_minutes' => 212, 'approved_minutes' => 212, 'payable_minutes' => 212,
            'status' => 'confirmed', 'activated_at' => now(), 'confirmed_at' => now(),
        ]);

        $slip = $this->hitung();

        $this->assertNull($this->item($slip, 'absent'), 'alpha tidak dipotong sebelum aturannya berlaku');

        $lembur = $this->item($slip, 'overtime');
        $this->assertSame('info', $lembur->category);
        $this->assertStringContainsString('Lembur 3 jam 32 menit: dibayar terpisah', $lembur->label);
        $this->assertSame(212, (int) $slip->overtime_minutes);
        $this->assertSame(3_000_000, (int) $slip->take_home_pay);
    }

    /** Potongan pulang cepat dan alpha punya rincian per tanggal. */
    public function test_potongan_pulang_cepat_dan_alpha_dirinci_per_tanggal(): void
    {
        $this->hadir('2026-08-25', pulangCepatMenit: 20);
        $this->hadir('2026-08-27', status: AttendanceStatus::Alpha);
        $this->hadir('2026-09-01', status: AttendanceStatus::Alpha);

        $slip = $this->hitung();

        $cepat = $this->item($slip, 'early_leave');
        $this->assertSame(10_000, (int) $cepat->amount);
        $this->assertSame('2026-08-25', $cepat->rule_snapshot['rincian'][0]['date']);
        $this->assertSame(20, $cepat->rule_snapshot['rincian'][0]['minutes']);

        $alpha = $this->item($slip, 'absent');
        $this->assertSame('Potongan Alpha (2 hari)', $alpha->label);
        $this->assertSame(2 * 115_384, (int) $alpha->amount);
        $this->assertSame(['2026-08-27', '2026-09-01'], array_column($alpha->rule_snapshot['rincian'], 'date'));
        $this->assertSame(115_384, (int) $alpha->rate);
    }

    /**
     * Kasbon dipotong otomatis, dan TETAP ada setelah hitung ulang — versi
     * lama sudah menandai cicilannya "deducted", versi baru harus mengambil
     * alih penanda itu, bukan melewatinya.
     */
    public function test_kasbon_dipotong_dan_bertahan_saat_hitung_ulang(): void
    {
        app(KasbonService::class)->catat($this->budi, 500_000, '2026-09', 2, 'keperluan keluarga', Carbon::parse('2026-09-01'));

        $pertama = $this->hitung();
        $kedua = $this->hitung();

        foreach ([$pertama, $kedua] as $slip) {
            $kasbon = $this->item($slip, 'cash_advance');
            $this->assertNotNull($kasbon, 'kasbon harus ada di slip versi '.$slip->run->version);
            $this->assertSame('Kasbon cicilan ke-1 dari 2', $kasbon->label);
            $this->assertSame(250_000, (int) $kasbon->amount);
            $this->assertSame(500_000, $kasbon->rule_snapshot['kasbon']['total']);
        }

        $cicilan = CashAdvance::sole()->installments;
        $this->assertSame('deducted', $cicilan[0]->status);
        $this->assertSame($this->item($kedua, 'cash_advance')->id, (int) $cicilan[0]->payslip_item_id, 'penanda pindah ke baris slip terbaru');

        // Cicilan kedua menunggu periode berikutnya (2026-10).
        $this->assertSame('scheduled', $cicilan[1]->status);
        $this->assertSame('2026-10', $cicilan[1]->period->code);
        $this->assertSame(250_000, (int) $kedua->total_deduction);
    }

    /** Akun yang tidak diabsen dan tidak bergaji (akun test) tidak diberi slip. */
    public function test_akun_test_tanpa_gaji_tidak_diberi_slip(): void
    {
        Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Test Akun',
            'pin_device' => '901',
            'tracks_attendance' => false,
        ]);

        $run = app(PayrollGenerator::class)->generate($this->periode->fresh());

        $this->assertSame(1, (int) $run->employee_count);
        $this->assertSame(['Budi Uji'], $run->payslips()->with('employee')->get()->pluck('employee.name')->all());
    }

    /** Halaman periode: kasbon bisa dicatat dari formulir dan statusnya terlihat. */
    public function test_kasbon_dicatat_dari_halaman_payroll(): void
    {
        $this->post(route('manajer.payroll.kasbon', $this->periode), [
            'employee_id' => $this->budi->id,
            'amount' => 400_000,
            'installments' => 2,
            'disbursed_at' => '2026-09-10',
            'reason' => 'servis motor',
        ])->assertRedirect()->assertSessionHas('status');

        $kasbon = CashAdvance::sole();
        $this->assertSame(400_000, (int) $kasbon->amount);
        $this->assertSame('2026-09-10', $kasbon->disbursed_at->toDateString());
        $this->assertSame(['2026-09', '2026-10'], $kasbon->installments->map(fn ($c) => $c->period->code)->all());

        $this->get(route('manajer.payroll.show', $this->periode))
            ->assertOk()
            ->assertSee('servis motor')
            ->assertSee('Menunggu hitung ulang');

        $this->hitung();

        $this->get(route('manajer.payroll.show', $this->periode))
            ->assertOk()
            ->assertSee('Sudah di slip');

        // Batalkan: cicilan Oktober yang belum terpotong dilepas, yang sudah
        // masuk slip September tidak ditarik kembali.
        $this->post(route('manajer.payroll.kasbon.batal', [$this->periode, $kasbon]))->assertRedirect();

        $kasbon->refresh();
        $this->assertSame('paid_off', $kasbon->status);
        $this->assertSame(['deducted', 'skipped'], $kasbon->installments->pluck('status')->all());
    }

    /** Halaman slip merender rincian tanpa error. */
    public function test_halaman_slip_menampilkan_rincian(): void
    {
        $this->hadir('2026-08-24', telatDetik: 11 * 60);
        $this->hadir('2026-08-21', telatDetik: 5 * 60);
        app(KasbonService::class)->catat($this->budi, 300_000, '2026-09', 1, null, Carbon::parse('2026-09-01'));

        $slip = $this->hitung();

        $this->get(route('manajer.payroll.payslip', $slip))
            ->assertOk()
            ->assertSee('Potongan Terlambat (1x)')
            ->assertSee('Rp1.000 per menit keterlambatan')
            ->assertSee('Toleransi telat 10 menit')
            ->assertSee('Kasbon')
            ->assertSee('Dasar perhitungan')
            ->assertSee('Tarif per jam');
    }
}
