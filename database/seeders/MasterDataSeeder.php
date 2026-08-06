<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DeductionType;
use App\Models\Division;
use App\Models\LeaveType;
use App\Models\NotificationTemplate;
use App\Models\RuleSet;
use App\Models\SalaryComponent;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StaffingRequirement;
use Illuminate\Database\Seeder;

/**
 * Data induk yang harus ada sebelum sistem bisa dipakai sama sekali.
 *
 * Nilai aturan awal sengaja diambil dari config/attendance.php yang sudah
 * berjalan, supaya memindahkan aturan ke database tidak diam-diam mengubah
 * perilaku sistem di hari peralihan.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['code' => 'pusat'],
            [
                'name' => config('app.name', 'Kafe'),
                'timezone' => config('attendance.timezone', 'Asia/Jakarta'),
                'is_active' => true,
            ],
        );

        $this->divisions();
        $this->shifts();
        $this->staffing($branch);
        $this->settings($branch);
        $this->leaveTypes();
        $this->salaryComponents();
        $this->deductionTypes();
        $this->rules($branch);
        $this->notificationTemplates();
    }

    protected function divisions(): void
    {
        $data = [
            ['code' => 'chef', 'name' => 'Chef', 'color' => '#ef4444', 'sort_order' => 1],
            ['code' => 'barista', 'name' => 'Barista', 'color' => '#f59e0b', 'sort_order' => 2],
            ['code' => 'kasir', 'name' => 'Kasir', 'color' => '#3b82f6', 'sort_order' => 3],
            ['code' => 'waiter', 'name' => 'Waiters', 'color' => '#10b981', 'sort_order' => 4],
            ['code' => 'cleaning', 'name' => 'Cleaning Service', 'color' => '#8b5cf6', 'sort_order' => 5],
        ];

        foreach ($data as $row) {
            Division::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    protected function shifts(): void
    {
        Shift::updateOrCreate(['name' => 'Shift Pagi'], [
            'code' => 'pagi',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'break_minutes' => 60,
            'is_break_paid' => true,
            'window_before_hours' => 4,
            'window_after_hours' => 4,
            'color' => '#fbbf24',
            'is_active' => true,
        ]);

        Shift::updateOrCreate(['name' => 'Shift Malam'], [
            'code' => 'malam',
            'start_time' => '17:00:00',
            'end_time' => '01:00:00',

            // Jam pulang lebih kecil dari jam masuk: shift ini melewati tengah
            // malam, dan scan pulangnya jatuh di tanggal berikutnya.
            'crosses_midnight' => true,

            'break_minutes' => 60,
            'is_break_paid' => true,
            'window_before_hours' => 4,
            'window_after_hours' => 4,
            'color' => '#6366f1',
            'is_active' => true,
        ]);
    }

    /** BR-11 & BR-12 sebagai data, bukan angka yang ditanam di kode. */
    protected function staffing(Branch $branch): void
    {
        $pagi = Shift::where('code', 'pagi')->first();
        $malam = Shift::where('code', 'malam')->first();
        $div = Division::pluck('id', 'code');

        $kebutuhan = [
            [$pagi->id, 'chef', 2],
            [$pagi->id, 'barista', 2],
            [$pagi->id, 'kasir', 1],
            [$pagi->id, 'waiter', 1],
            [$pagi->id, 'cleaning', 1],

            [$malam->id, 'chef', 3],
            [$malam->id, 'barista', 2],
            [$malam->id, 'kasir', 1],
            [$malam->id, 'waiter', 3],
            [$malam->id, 'cleaning', 1],
        ];

        foreach ($kebutuhan as [$shiftId, $divisionCode, $count]) {
            StaffingRequirement::updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'shift_id' => $shiftId,
                    'division_id' => $div[$divisionCode],
                    'day_type' => 'all',
                ],
                [
                    'required_count' => $count,
                    'effective_from' => now()->startOfYear(),
                ],
            );
        }
    }

    protected function settings(Branch $branch): void
    {
        $data = [
            ['roster.min_rest_hours', 10, 'int', 'Jeda minimal antar shift (jam)'],
            ['roster.max_consecutive_days', 6, 'int', 'Maksimal hari kerja beruntun'],
            ['roster.target_off_days_per_week', 1, 'int', 'Target libur per minggu'],
            ['roster.warn_double_shift', true, 'bool', 'Peringatkan kalau ada double shift'],
            ['attendance.check_in_out_strategy', 'earliest_latest', 'string', 'Cara menentukan jam masuk & pulang'],
            ['attendance.close_day_hour', 6, 'int', 'Jam proses tutup hari'],
            ['overtime.min_minutes', 60, 'int', 'Minimal lembur (menit)'],
            ['overtime.allow_backdated', true, 'bool', 'Izinkan approval lembur susulan'],
            ['payroll.period_start_day', 21, 'int', 'Tanggal mulai periode gaji'],
            ['payroll.pay_day', 21, 'int', 'Tanggal pembayaran gaji'],
            ['payroll.working_days_basis', 'scheduled', 'string', 'Dasar hari kerja'],
        ];

        foreach ($data as [$key, $value, $type, $label]) {
            Setting::updateOrCreate(
                ['branch_id' => $branch->id, 'key' => $key],
                [
                    'group' => explode('.', $key)[0],
                    'value' => $value,
                    'type' => $type,
                    'label' => $label,
                ],
            );
        }
    }

    protected function leaveTypes(): void
    {
        $data = [
            [
                'code' => 'cuti_tahunan', 'name' => 'Cuti Tahunan',
                'is_paid' => true, 'deducts_balance' => true, 'requires_evidence' => false,
                'max_days_per_request' => 12, 'min_notice_days' => 3, 'default_entitlement_days' => 12,
            ],
            [
                'code' => 'sakit', 'name' => 'Sakit',
                'is_paid' => true, 'deducts_balance' => false, 'requires_evidence' => true,
                'max_days_per_request' => 14, 'min_notice_days' => 0, 'default_entitlement_days' => 0,
            ],
            [
                // Tidak dibayar dan tidak memotong kuota — inilah yang
                // membedakannya dari cuti. Tanpa pembedaan ini, "izin" dan
                // "cuti" cuma dua label untuk hal yang sama.
                'code' => 'izin', 'name' => 'Izin',
                'is_paid' => false, 'deducts_balance' => false, 'requires_evidence' => false,
                'max_days_per_request' => 3, 'min_notice_days' => 1, 'default_entitlement_days' => 0,
            ],
            [
                'code' => 'cuti_melahirkan', 'name' => 'Cuti Melahirkan',
                'is_paid' => true, 'deducts_balance' => false, 'requires_evidence' => true,
                'max_days_per_request' => 90, 'min_notice_days' => 30, 'default_entitlement_days' => 0,
            ],
        ];

        foreach ($data as $row) {
            LeaveType::updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }
    }

    protected function salaryComponents(): void
    {
        $data = [
            ['code' => 'gaji_pokok', 'name' => 'Gaji Pokok', 'category' => 'earning', 'calc_type' => 'fixed', 'sort_order' => 1],
            ['code' => 'tunjangan_jabatan', 'name' => 'Tunjangan Jabatan', 'category' => 'earning', 'calc_type' => 'fixed', 'sort_order' => 2],
            ['code' => 'uang_makan', 'name' => 'Uang Makan', 'category' => 'earning', 'calc_type' => 'per_day', 'sort_order' => 3],
            ['code' => 'lembur', 'name' => 'Lembur', 'category' => 'earning', 'calc_type' => 'per_hour', 'sort_order' => 4],
        ];

        foreach ($data as $row) {
            SalaryComponent::updateOrCreate(['code' => $row['code']], $row + ['is_taxable' => true, 'is_active' => true]);
        }
    }

    protected function deductionTypes(): void
    {
        $data = [
            ['code' => 'telat', 'name' => 'Terlambat', 'is_system' => true],
            ['code' => 'pulang_cepat', 'name' => 'Pulang Cepat', 'is_system' => true],
            ['code' => 'alpha', 'name' => 'Alpha', 'is_system' => true],
            ['code' => 'kasbon', 'name' => 'Kasbon', 'is_system' => false],
            ['code' => 'denda', 'name' => 'Denda', 'is_system' => false],
            ['code' => 'lainnya', 'name' => 'Lainnya', 'is_system' => false],
        ];

        foreach ($data as $row) {
            DeductionType::updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }
    }

    /**
     * Aturan awal.
     *
     * Potongan telat memakai tingkatan yang disebut brief (1-10, 11-30, 31-60,
     * >60), bukan blok 10 menit berulang seperti config lama. Nominalnya
     * ditetapkan sebagai titik awal yang wajar dan bisa langsung diubah manager
     * dari menu Aturan.
     */
    protected function rules(Branch $branch): void
    {
        $awalTahun = now()->startOfYear()->toDateString();

        $late = RuleSet::updateOrCreate(
            ['branch_id' => $branch->id, 'type' => 'late', 'effective_from' => $awalTahun],
            ['name' => 'Potongan Terlambat ' . now()->year, 'is_active' => true],
        );
        $late->tiers()->delete();
        $late->tiers()->createMany([
            ['min_value' => 1, 'max_value' => 10, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 5000, 'label' => 'Telat 1–10 menit', 'sort_order' => 1],
            ['min_value' => 11, 'max_value' => 30, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 15000, 'label' => 'Telat 11–30 menit', 'sort_order' => 2],
            ['min_value' => 31, 'max_value' => 60, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 30000, 'label' => 'Telat 31–60 menit', 'sort_order' => 3],
            ['min_value' => 61, 'max_value' => null, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 50000, 'label' => 'Telat di atas 1 jam', 'sort_order' => 4],
        ]);

        $early = RuleSet::updateOrCreate(
            ['branch_id' => $branch->id, 'type' => 'early_leave', 'effective_from' => $awalTahun],
            ['name' => 'Potongan Pulang Cepat ' . now()->year, 'is_active' => true],
        );
        $early->tiers()->delete();
        $early->tiers()->createMany([
            ['min_value' => 1, 'max_value' => 30, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 10000, 'label' => 'Pulang cepat 1–30 menit', 'sort_order' => 1],
            ['min_value' => 31, 'max_value' => 60, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 25000, 'label' => 'Pulang cepat 31–60 menit', 'sort_order' => 2],
            ['min_value' => 61, 'max_value' => null, 'unit' => 'minute', 'calc_type' => 'flat', 'value' => 50000, 'label' => 'Pulang cepat di atas 1 jam', 'sort_order' => 3],
        ]);

        // Mengikuti pola umum: jam pertama 1,5x upah sejam, jam berikutnya 2x.
        $overtime = RuleSet::updateOrCreate(
            ['branch_id' => $branch->id, 'type' => 'overtime', 'effective_from' => $awalTahun],
            ['name' => 'Tarif Lembur ' . now()->year, 'is_active' => true],
        );
        $overtime->tiers()->delete();
        $overtime->tiers()->createMany([
            ['min_value' => 1, 'max_value' => 1, 'unit' => 'hour', 'calc_type' => 'hourly_multiplier', 'value' => 1.5, 'label' => 'Lembur jam ke-1', 'sort_order' => 1],
            ['min_value' => 2, 'max_value' => null, 'unit' => 'hour', 'calc_type' => 'hourly_multiplier', 'value' => 2.0, 'label' => 'Lembur jam ke-2 dst', 'sort_order' => 2],
        ]);

        // Alpha = satu hari gaji per hari alpha (D-05).
        $absent = RuleSet::updateOrCreate(
            ['branch_id' => $branch->id, 'type' => 'absent', 'effective_from' => $awalTahun],
            ['name' => 'Potongan Alpha ' . now()->year, 'is_active' => true],
        );
        $absent->tiers()->delete();
        $absent->tiers()->createMany([
            ['min_value' => 1, 'max_value' => null, 'unit' => 'day', 'calc_type' => 'daily_rate', 'value' => 1.0, 'label' => 'Alpha per hari', 'sort_order' => 1],
        ]);

        // Porsi karyawan: JHT 2%, JP 1%, Kesehatan 1%. Porsi perusahaan tidak
        // dipotong dari gaji jadi tidak muncul di sini.
        $bpjs = RuleSet::updateOrCreate(
            ['branch_id' => $branch->id, 'type' => 'bpjs', 'effective_from' => $awalTahun],
            ['name' => 'BPJS ' . now()->year, 'is_active' => true],
        );
        $bpjs->tiers()->delete();
        $bpjs->tiers()->createMany([
            ['min_value' => 0, 'max_value' => null, 'unit' => 'percent', 'calc_type' => 'percent_of_base', 'value' => 1.0, 'label' => 'BPJS Kesehatan (1%)', 'sort_order' => 1],
            ['min_value' => 0, 'max_value' => null, 'unit' => 'percent', 'calc_type' => 'percent_of_base', 'value' => 2.0, 'label' => 'BPJS JHT (2%)', 'sort_order' => 2],
            ['min_value' => 0, 'max_value' => null, 'unit' => 'percent', 'calc_type' => 'percent_of_base', 'value' => 1.0, 'label' => 'BPJS Jaminan Pensiun (1%)', 'sort_order' => 3],
        ]);
    }

    protected function notificationTemplates(): void
    {
        $data = [
            ['code' => 'request.submitted', 'subject' => 'Pengajuan baru', 'body_template' => '{employee} mengajukan {type} ({code}).'],
            ['code' => 'request.approved', 'subject' => 'Pengajuan disetujui', 'body_template' => 'Pengajuan {code} Anda disetujui.'],
            ['code' => 'request.rejected', 'subject' => 'Pengajuan ditolak', 'body_template' => 'Pengajuan {code} Anda ditolak: {note}'],
            ['code' => 'roster.published', 'subject' => 'Jadwal terbit', 'body_template' => 'Jadwal {period} sudah terbit.'],
            ['code' => 'payslip.published', 'subject' => 'Slip gaji terbit', 'body_template' => 'Slip gaji periode {period} sudah bisa dilihat.'],
        ];

        foreach ($data as $row) {
            NotificationTemplate::updateOrCreate(
                ['code' => $row['code']],
                $row + ['channel' => 'database', 'is_active' => true],
            );
        }
    }
}
