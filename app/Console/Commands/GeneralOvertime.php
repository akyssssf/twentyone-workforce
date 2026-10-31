<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Enums\OvertimeOccasion as OccasionKode;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\RosterAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Support\DateInput;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Lembur untuk hari kerja bersama (mis. General Cleaning) — dihitung dari JAM
 * SCAN, dengan pengali tarif.
 *
 * lembur:tugaskan tidak cocok di sini: ia mengira lembur SELALU menyambung
 * setelah jam pulang shift, sedangkan di hari seperti ini orang datang LEBIH
 * AWAL (yang shift malam) atau datang di hari liburnya. Jam yang dibayar
 * di sini diambil dari scan masuk sampai batas yang ditetapkan, dan setiap
 * angka ditampilkan dulu (tanpa --simpan tidak ada yang berubah) supaya
 * jumlah jamnya bisa diperiksa orang sebelum jadi uang.
 *
 * Aturan, sama untuk semua orang dan TIDAK bergantung pada roster:
 *   - mulai  = scan pertama hari itu (setelah --awal, bawaan 05:00)
 *   - selesai = scan terakhir sebelum --batas (bawaan 13:00), atau --batas itu
 *               sendiri kalau tidak ada scan pulang; tidak pernah melewatinya
 *   - yang berhak: dibatasi --pin (hanya mereka) atau --abaikan (lewati mereka);
 *     kalau kode sudah dibuat (lembur:kode-umum), hanya yang sudah
 *     mengaktifkan kodenya.
 * Kolom Roster di tabel cuma keterangan. Roster boleh (dan sebaiknya) sudah
 * diperbaiki jadi "pagi" untuk acara itu.
 *
 * Disahkan atas nama admin dan dilindungi dari perhitungan ulang otomatis.
 * Scan adalah buktinya, jadi tanpa kode aktivasi.
 */
class GeneralOvertime extends Command
{
    protected $signature = 'lembur:umum
                            {tanggal : Tanggal acara (YYYY-MM-DD)}
                            {--pengali=2 : Pengali tarif lembur biasa}
                            {--batas=13:00 : Jam paling akhir yang dihitung untuk SEMUA orang}
                            {--awal=05:00 : Scan sebelum jam ini tidak dianggap}
                            {--pin=* : Hanya PIN ini (boleh diulang)}
                            {--abaikan=* : Lewati PIN ini (boleh diulang)}
                            {--wajib-kode : Hanya yang SUDAH memasukkan kodenya sendiri (bawaan: kode dipakai otomatis untuk yang punya scan)}
                            {--tanpa-notif : Jangan buat pemberitahuan di portal karyawan}
                            {--tambah=* : Jam manual untuk yang lupa scan: PIN:08:00-13:00 (boleh diulang; menggantikan hitungan dari scan)}
                            {--catatan=General cleaning : Tertulis di slip}
                            {--simpan : Simpan. Tanpa ini hanya menampilkan hitungannya}';

    protected $description = 'Lembur hari kerja bersama dari jam scan dengan pengali tarif (mis. General Cleaning x2)';

    public function handle(): int
    {
        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $pengali = (float) $this->option('pengali');

        if ($pengali < 1 || $pengali > 5) {
            $this->error('--pengali harus antara 1 dan 5.');

            return self::FAILURE;
        }

        $awal = $this->jam($tanggal, (string) $this->option('awal'), $timezone);
        $batas = $this->jam($tanggal, (string) $this->option('batas'), $timezone);

        if ($awal === null || $batas === null) {
            $this->error('Format jam harus HH:MM, mis. --batas=13:00');

            return self::FAILURE;
        }

        $pins = array_map('strval', (array) $this->option('pin'));
        $abaikan = array_map('strval', (array) $this->option('abaikan'));

        $karyawan = Employee::query()->tracked()->employed()->orderBy('name')->get()
            ->when($pins !== [], fn ($c) => $c->filter(fn ($e) => in_array((string) $e->pin_device, $pins, true)))
            ->reject(fn ($e) => in_array((string) $e->pin_device, $abaikan, true));

        // Kode lembur umum yang sudah dibuat (lembur:kode-umum). Bawaannya kode
        // dipakai OTOMATIS untuk yang punya scan, walau orangnya tidak pernah
        // memintanya atau memasukkannya (permintaan manajer); orangnya diberi
        // tahu. --wajib-kode mengembalikan syarat lama: hanya yang memasukkan
        // kodenya sendiri.
        $catatanKode = OvertimeRecord::query()
            ->with('overtimeRequest')
            ->whereDate('work_date', $tanggal)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->filter(fn (OvertimeRecord $r) => $r->overtimeRequest?->occasion === OccasionKode::GeneralCleaning)
            ->keyBy('employee_id');

        $pakaiKode = $catatanKode->isNotEmpty() && $this->option('wajib-kode');
        $belumAktif = [];

        $baris = [];
        $hitungan = [];

        foreach ($karyawan as $e) {
            // Roster hanya KETERANGAN di tabel, bukan penentu. Penentu dulu
            // rosternya (libur / shift sore / pagi), dan itu rusak begitu
            // rosternya diperbaiki jadi "pagi semua" — orang yang shift
            // malamnya diubah ke pagi mendadak dianggap pagi biasa tanpa
            // lembur. Sekarang yang menentukan: ada scan, dan (kalau kode
            // sudah dibuat) sudah mengaktifkan kodenya. Siapa yang BERHAK
            // lembur dibatasi lewat --pin atau --abaikan.
            $jenis = RosterAssignment::query()
                ->with('shift')
                ->where('employee_id', $e->id)
                ->whereDate('work_date', $tanggal)
                ->where('status', '!=', AssignmentStatus::Cancelled->value)
                ->get()
                ->map(fn (RosterAssignment $r) => $r->shift?->name ?? 'LIBUR')
                ->implode(' + ') ?: 'tanpa roster';

            $scan = AttendanceLog::query()
                ->where('pin', (string) $e->pin_device)
                ->whereBetween('scanned_at', [$awal, $tanggal->copy()->setTimezone($timezone)->setTime(23, 59, 59)])
                ->orderBy('scanned_at')
                ->get()
                ->map(fn (AttendanceLog $l) => $l->scanned_at->copy()->setTimezone($timezone));

            if ($scan->isEmpty()) {
                continue;
            }

            $mulai = $scan->first();

            if ($mulai->greaterThan($batas)) {
                continue;
            }

            // Tanpa scan pulang: dianggap sampai batas. Dengan scan pulang
            // sebelum batas: sampai scan itu. Tidak pernah melewati batas.
            // Scan kurang dari 1 jam setelah scan masuk = tempel jari dua kali, bukan pulang.
            $dalamRentang = $scan->filter(fn (Carbon $x) => $x->lessThanOrEqualTo($batas->copy()->addMinutes(30))
                && ($x->equalTo($mulai) || $mulai->diffInMinutes($x) >= 60));
            $selesai = $dalamRentang->count() > 1 ? $dalamRentang->last() : $batas->copy();
            $selesai = $selesai->greaterThan($batas) ? $batas->copy() : $selesai;

            $menit = (int) max(0, floor($mulai->diffInSeconds($selesai) / 60));

            if ($menit <= 0) {
                continue;
            }

            if ($pakaiKode && ! $catatanKode->get($e->id)?->isActivated()) {
                $belumAktif[] = $e->name;

                continue;
            }

            $punyaKodeCatatan = $catatanKode->get($e->id);
            $statusKode = $punyaKodeCatatan === null
                ? 'tanpa kode'
                : ($punyaKodeCatatan->isActivated() ? 'dimasukkan sendiri' : 'dipakai otomatis');

            $baris[] = [$e->pin_device, $e->name, $jenis, $mulai->format('H:i:s'), $selesai->format('H:i'), Durasi::menit($menit), '×'.rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ','), $statusKode];
            $hitungan[] = ['employee' => $e, 'mulai' => $mulai, 'selesai' => $selesai, 'menit' => $menit, 'jenis' => $jenis];
        }

        // Jam manual untuk yang lupa scan (atau scannya keliru). Menggantikan
        // hitungan dari scan untuk PIN itu, dan ditandai "Manual" supaya terlihat
        // di tabel mana yang bukan dari mesin.
        foreach ((array) $this->option('tambah') as $entri) {
            if (! preg_match('/^(\w+):(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', trim((string) $entri), $m)) {
                $this->error("Format --tambah salah: \"{$entri}\". Harus PIN:08:00-13:00");

                return self::FAILURE;
            }

            $orang = Employee::where('pin_device', $m[1])->first();
            $dari = $this->jam($tanggal, $m[2], $timezone);
            $sampai = $this->jam($tanggal, $m[3], $timezone);

            if ($orang === null || $dari === null || $sampai === null || $sampai->lessThanOrEqualTo($dari)) {
                $this->error("--tambah {$entri}: PIN tidak ada, atau jam selesai tidak setelah jam masuk.");

                return self::FAILURE;
            }

            $menit = (int) floor($dari->diffInSeconds($sampai) / 60);

            $baris = array_values(array_filter($baris, fn ($b) => (string) $b[0] !== (string) $orang->pin_device));
            $hitungan = array_values(array_filter($hitungan, fn ($h) => $h['employee']->id !== $orang->id));

            $baris[] = [$orang->pin_device, $orang->name, 'Manual', $dari->format('H:i:s'), $sampai->format('H:i'), Durasi::menit($menit), '×'.rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ','), $catatanKode->has($orang->id) ? ($catatanKode->get($orang->id)->isActivated() ? 'dimasukkan sendiri' : 'dipakai otomatis') : 'tanpa kode'];
            $hitungan[] = ['employee' => $orang, 'mulai' => $dari, 'selesai' => $sampai, 'menit' => $menit, 'jenis' => 'Manual'];
        }

        $this->newLine();
        $this->line("Lembur umum {$tanggal->translatedFormat('l, d F Y')} — pengali ×{$pengali}");

        if ($baris === []) {
            $this->warn('Tidak ada yang memenuhi syarat. Mungkin scan belum masuk, atau semua orang jadwalnya shift pagi.');

            return self::SUCCESS;
        }

        if ($belumAktif !== []) {
            $this->warn('Ada scan tapi BELUM mengaktifkan kode (tidak dihitung karena --wajib-kode): '.implode(', ', $belumAktif));
        }

        $this->table(['PIN', 'Nama', 'Roster', 'Masuk', 'Sampai', 'Lama', 'Pengali', 'Kode'], $baris);
        $this->line(sprintf('%d orang · total %s (belum dikali pengali)', count($hitungan), Durasi::menit((int) collect($hitungan)->sum('menit'))));

        if (! $this->option('simpan')) {
            $this->newLine();
            $this->warn('Belum disimpan. Periksa jamnya, lalu ulangi dengan --simpan (atau --abaikan=PIN untuk melewati seseorang).');

            return self::SUCCESS;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();

        $tersimpan = 0;
        $dilewati = [];

        $diberitahu = [];

        DB::transaction(function () use ($hitungan, $tanggal, $pengali, $admin, $catatanKode, &$tersimpan, &$dilewati, &$diberitahu) {
            foreach ($hitungan as $h) {
                $punyaKode = $catatanKode->get($h['employee']->id);

                $sudah = OvertimeRecord::query()
                    ->where('employee_id', $h['employee']->id)
                    ->whereDate('work_date', $tanggal)
                    ->where('multiplier', '>', 1)
                    ->where('status', 'confirmed')
                    ->exists();

                if ($sudah) {
                    $dilewati[] = $h['employee']->name;

                    continue;
                }

                $catatan = sprintf('%s %s–%s, tarif ×%s', $this->option('catatan'), $h['mulai']->format('H:i'), $h['selesai']->format('H:i'), rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ','));

                // Catatan yang sudah dibuat bersama kodenya DIISI, bukan dibuat
                // baru — kalau tidak, orang yang sama punya dua catatan lembur.
                $nilai = [
                    'employee_id' => $h['employee']->id,
                    'work_date' => $tanggal,
                    'actual_start' => $h['mulai'],
                    'actual_end' => $h['selesai'],
                    'actual_minutes' => $h['menit'],
                    'approved_minutes' => $h['menit'],
                    'payable_minutes' => $h['menit'],
                    'multiplier' => $pengali,
                    'status' => 'confirmed',

                    // confirmed_by terisi supaya perhitungan otomatis lima belas
                    // menit sekali (yang hanya menyentuh catatan yang belum
                    // disahkan manusia) tidak menimpanya.
                    'confirmed_by' => $admin?->id,
                    'confirmed_at' => now(),
                    'activated_at' => now(),
                    'activated_by' => $admin?->id,
                    'note' => $catatan,
                ];

                $otomatis = $punyaKode !== null && ! $punyaKode->isActivated();

                if ($punyaKode !== null) {
                    $punyaKode->update($nilai + [
                        'activated_at' => $punyaKode->activated_at ?? now(),
                        'activated_by' => $punyaKode->activated_by ?? $admin?->id,
                        // Ditandai supaya halaman Lembur karyawan bisa bilang
                        // "tercatat otomatis" (hanya kalau orangnya tidak memasukkan sendiri).
                        'activated_auto' => $punyaKode->isActivated() ? $punyaKode->activated_auto : true,
                    ]);
                    $record = $punyaKode->fresh();
                } else {
                    $record = OvertimeRecord::create($nilai);
                }

                AuditLogger::record('overtime.general', $record, [], [
                    'employee' => $h['employee']->name, 'minutes' => $h['menit'], 'multiplier' => $pengali,
                ]);

                $tersimpan++;

                // Kode yang tidak pernah dimasukkan orangnya dipakai OTOMATIS;
                // orangnya diberi tahu supaya tidak bingung kenapa lemburnya
                // tercatat, dan tahu jam yang dipakai.
                if ($otomatis) {
                    $diberitahu[] = ['employee' => $h['employee'], 'record' => $record, 'kode' => $punyaKode->overtimeRequest?->secret_code, 'mulai' => $h['mulai'], 'selesai' => $h['selesai'], 'menit' => $h['menit']];
                }
            }
        });

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        // Pemberitahuan LEWAT PORTAL (bukan WhatsApp): satu baris di lonceng
        // beranda, dan kartu "tercatat otomatis" di halaman Lembur yang dibaca
        // dari catatan lemburnya sendiri.
        $terkirim = 0;
        $tanpaAkun = [];

        if ($diberitahu !== [] && ! $this->option('tanpa-notif')) {
            $notifier = app(Notifier::class);

            foreach ($diberitahu as $d) {
                $akun = $d['employee']->user;

                if ($akun === null) {
                    $tanpaAkun[] = $d['employee']->name;

                    continue;
                }

                $notifier->send($akun, 'Lembur General Cleaning tercatat', $this->pesan($d, $tanggal, $pengali), route('karyawan.lembur.index'));
                $terkirim++;
            }
        }

        $this->newLine();
        $this->info("{$tersimpan} lembur tersimpan (tarif ×{$pengali}, masuk slip bonus periode yang mencakup {$tanggal->toDateString()}).");

        if ($dilewati !== []) {
            $this->warn('Dilewati karena sudah punya lembur berpengali di tanggal itu: '.implode(', ', $dilewati));
        }

        if ($diberitahu !== []) {
            $this->line(sprintf('%d orang kodenya dipakai otomatis; %s',
                count($diberitahu),
                $this->option('tanpa-notif') ? 'pemberitahuan di portal TIDAK dibuat (--tanpa-notif).' : "{$terkirim} pemberitahuan muncul di portal mereka."));
        }

        if ($tanpaAkun !== []) {
            $this->warn('Belum punya akun portal (tidak ada pemberitahuan, lemburnya tetap tersimpan): '.implode(', ', $tanpaAkun));
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $d */
    protected function pesan(array $d, Carbon $tanggal, float $pengali): string
    {
        $x = rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ',');

        return sprintf(
            "Halo %s, lembur General Cleaning %s sudah tercatat otomatis.\n".
            "%s\n".
            "Jam dihitung dari scan: %s–%s (%s), tarif lembur ×%s.\n".
            'Kalau jam ini tidak sesuai, hubungi manajer.',
            $d['employee']->name,
            $tanggal->translatedFormat('d F Y'),
            $d['kode'] ? "Kode lembur {$d['kode']} terpakai otomatis, kamu tidak perlu memasukkannya." : 'Kamu tidak perlu memasukkan kode.',
            $d['mulai']->format('H:i'),
            $d['selesai']->format('H:i'),
            Durasi::menit($d['menit']),
            $x,
        );
    }

    protected function jam(Carbon $tanggal, string $hhmm, string $timezone): ?Carbon
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }

        return Carbon::create($tanggal->year, $tanggal->month, $tanggal->day, (int) $m[1], (int) $m[2], 0, $timezone);
    }
}
