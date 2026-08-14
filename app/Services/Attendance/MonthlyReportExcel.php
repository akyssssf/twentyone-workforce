<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pembuat berkas Excel rekap bulanan.
 *
 * Dua sheet:
 *   1. Ringkasan  - satu baris per karyawan, siap dipakai hitung gajian.
 *   2. Rincian    - satu baris per karyawan per tanggal, buat menelusuri
 *                   kalau ada karyawan yang protes angkanya.
 *
 * Angka rupiah dan durasi ditulis sebagai ANGKA, bukan teks yang sudah
 * diformat. Jadi kalau owner mau menjumlah atau memfilter sendiri di Excel,
 * semuanya masih bisa dihitung.
 */
class MonthlyReportExcel
{
    protected const WARNA_JUDUL = 'FF1E293B';   // slate-800

    protected const WARNA_TOTAL = 'FFF1F5F9';   // slate-100

    /**
     * Menit ditulis sebagai angka biasa supaya tetap bisa dijumlah, tapi
     * ditampilkan dengan satuannya sendiri — "723" tanpa keterangan gampang
     * disangka jam atau rupiah oleh yang membuka berkasnya.
     */
    protected const FORMAT_MENIT = '#,##0" m"';

    public function __construct(
        protected MonthlyReport $report,
    ) {}

    /**
     * Tulis berkas ke path tertentu.
     */
    public function simpan(string $path): string
    {
        $spreadsheet = $this->buat();

        (new Xlsx($spreadsheet))->save($path);

        // PhpSpreadsheet menahan seluruh sheet di memori. Untuk kafe dengan
        // belasan karyawan ini kecil, tapi tetap dilepas supaya tidak menumpuk
        // kalau nanti dipanggil berulang dalam satu proses.
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function buat(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        $spreadsheet->getProperties()
            ->setTitle('Rekap Absensi '.$this->report->judulPeriode())
            ->setCreator(config('app.name'));

        $this->sheetRingkasan($spreadsheet->getActiveSheet());
        $this->sheetRincian($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    protected function sheetRingkasan(Worksheet $sheet): void
    {
        $sheet->setTitle('Ringkasan');

        // Judul dan keterangan direntang sampai N, bukan M — kolomnya ada 14
        // (A sampai N), dan merge yang kependekan bikin blok judulnya terlihat
        // terpotong di tengah tabel.
        $sheet->setCellValue('A1', 'Rekap Absensi '.$this->report->judulPeriode());
        $sheet->mergeCells('A1:N1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Dibuat '.now(config('attendance.timezone'))->translatedFormat('d F Y H:i'));
        $sheet->mergeCells('A2:N2');
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setARGB('FF64748B');

        $judul = [
            'PIN', 'Nama', 'Shift', 'Hadir', 'Telat', 'Pulang Cepat', 'Alpha',
            'Izin', 'Sakit', 'Cuti', 'Libur', 'Hari Tercatat',
            'Total Telat', 'Lembur',
        ];

        $sheet->fromArray($judul, null, 'A4');
        $this->gayaJudul($sheet, 'A4:N4');

        $baris = 5;

        foreach ($this->report->ringkasan() as $data) {
            // strictNullComparison WAJIB true. Bawaannya false, dan itu bikin
            // fromArray membandingkan longgar dengan null — di PHP 0 == null
            // bernilai true, sehingga SETIAP angka nol ditulis jadi sel kosong.
            // Di laporan gajian, "0 alpha" dan "Rp 0 potongan" akan tampil
            // blank dan terlihat seperti data yang tidak terisi.
            $sheet->fromArray([
                $data['pin'],
                $data['nama'],
                $data['shift'],
                $data['hadir'],
                $data['telat'],
                $data['pulang_cepat'],
                $data['alpha'],
                $data['izin'],
                $data['sakit'],
                $data['cuti'],
                $data['libur'],
                $data['hari_tercatat'],
                // Menit bulat, bukan pecahan. Detik tidak menambah apa pun
                // selain "12,5" yang terlihat seperti angka yang belum jadi;
                // menitnya sendiri sudah dibulatkan ke atas saat dihitung.
                $data['total_telat_menit'],
                $data['total_lembur_menit'],
            ], null, 'A'.$baris, strictNullComparison: true);

            $baris++;
        }

        $barisTerakhir = $baris - 1;
        $adaData = $barisTerakhir >= 5;

        if ($adaData) {
            // Baris total memakai rumus SUM, bukan angka mati, supaya kalau
            // owner mengubah satu nilai, totalnya ikut menyesuaikan.
            $sheet->setCellValue('A'.$baris, 'TOTAL');
            $sheet->mergeCells("A{$baris}:C{$baris}");

            foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $kolom) {
                $sheet->setCellValue($kolom.$baris, "=SUM({$kolom}5:{$kolom}{$barisTerakhir})");
            }

            $sheet->getStyle("A{$baris}:N{$baris}")->getFont()->setBold(true);
            $sheet->getStyle("A{$baris}:N{$baris}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::WARNA_TOTAL);

            // Tidak ada lagi kolom rupiah di sini: laporan absensi melaporkan
            // fakta, nominalnya ada di slip gaji.
            $sheet->getStyle("A4:N{$baris}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
            $sheet->getStyle("D5:N{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Kolom menit ikut baris TOTAL: rumus SUM-nya juga perlu satuan,
            // kalau tidak totalnya jadi satu-satunya angka telanjang di sheet.
            $sheet->getStyle("M5:N{$baris}")->getNumberFormat()->setFormatCode(self::FORMAT_MENIT);

            // Penyaring di baris judul supaya owner bisa mengurutkan sendiri
            // per nama atau per jumlah alpha tanpa minta laporan baru.
            $sheet->setAutoFilter("A4:N{$barisTerakhir}");
        }

        $this->lebarOtomatis($sheet, 'A', 'N');

        // Nama karyawan ikut terkunci, bukan cuma baris judulnya: begitu
        // digulir ke kanan sampai kolom lembur, tanpa ini tidak ada lagi
        // penanda barisnya milik siapa.
        $sheet->freezePane('C5');
    }

    protected function sheetRincian(Worksheet $sheet): void
    {
        $sheet->setTitle('Rincian Harian');

        $judul = [
            'Tanggal', 'PIN', 'Nama', 'Shift', 'Jadwal Masuk',
            'Scan Masuk', 'Scan Pulang', 'Telat', 'Pulang Cepat', 'Status',
        ];

        $sheet->fromArray($judul, null, 'A1');
        $this->gayaJudul($sheet, 'A1:J1');

        $baris = 2;

        foreach ($this->report->rincian() as $data) {
            $sheet->fromArray([
                $data['tanggal']->format('Y-m-d'),
                $data['pin'],
                $data['nama'],
                $data['shift'],
                $data['jadwal']?->format('H:i') ?? '-',
                $data['masuk']?->format('H:i:s') ?? '-',
                // Shift malam pulangnya di tanggal berikutnya, jadi tanggal
                // ikut ditulis supaya tidak terbaca seperti salah ketik.
                $this->waktuPulang($data),
                $data['telat_menit'],
                $data['pulang_cepat_menit'],
                // Label, bukan nilai mentah: sheet ini dibaca manusia, dan
                // "Hadir" lebih jelas daripada "hadir" berjejer dengan "alpha".
                $data['status'] instanceof AttendanceStatus
                    ? $data['status']->label()
                    : (string) $data['status'],
            ], null, 'A'.$baris, strictNullComparison: true);

            $baris++;
        }

        if ($baris > 2) {
            $akhir = $baris - 1;

            $sheet->getStyle("A1:J{$akhir}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
            $sheet->getStyle("H2:I{$akhir}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("H2:I{$akhir}")->getNumberFormat()->setFormatCode(self::FORMAT_MENIT);

            // Filter kolom supaya owner bisa menyaring sendiri per nama atau
            // per status tanpa perlu minta laporan baru.
            $sheet->setAutoFilter("A1:J{$akhir}");
        }

        $this->lebarOtomatis($sheet, 'A', 'J');
        $sheet->freezePane('D2');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function waktuPulang(array $data): string
    {
        if ($data['pulang'] === null) {
            return '-';
        }

        $pulang = $data['pulang'];

        return $pulang->isSameDay($data['tanggal'])
            ? $pulang->format('H:i:s')
            : $pulang->format('H:i:s').' (+1 hari)';
    }

    protected function gayaJudul(Worksheet $sheet, string $rentang): void
    {
        $gaya = $sheet->getStyle($rentang);

        $gaya->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $gaya->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::WARNA_JUDUL);
        $gaya->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        $sheet->getRowDimension((int) filter_var($rentang, FILTER_SANITIZE_NUMBER_INT))->setRowHeight(28);
    }

    protected function lebarOtomatis(Worksheet $sheet, string $dari, string $sampai): void
    {
        foreach (range($dari, $sampai) as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }
    }
}
