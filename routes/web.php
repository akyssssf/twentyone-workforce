<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Karyawan\EmployeePortalController;
use App\Http\Controllers\Karyawan\EmployeeRequestController;
use App\Http\Controllers\Karyawan\PayslipController;
use App\Http\Controllers\Manajer\AuditController;
use App\Http\Controllers\Manajer\EmployeeController;
use App\Http\Controllers\Manajer\PayrollController;
use App\Http\Controllers\Manajer\RequestApprovalController;
use App\Http\Controllers\Manajer\RosterController;
use App\Http\Controllers\Manajer\RuleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanActivityController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsEmployee;
use App\Http\Middleware\EnsureUserIsManagement;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute web
|--------------------------------------------------------------------------
|
| Dua area yang benar-benar terpisah:
|
|   /manajer  — hanya manager & owner
|   /karyawan — hanya akun yang terhubung ke satu baris employees
|
| Pemisahan di level rute ini adalah lapis pertama RBAC. Lapis kedua ada di
| policy per baris: karyawan yang membuka slip gaji orang lain harus kena 403,
| bukan sekadar tidak melihat tautannya di menu.
|
*/

Route::redirect('/', '/beranda');

Route::middleware('guest')->group(function () {
    Route::get('masuk', [LoginController::class, 'show'])->name('login');
    Route::post('masuk', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::post('keluar', [LoginController::class, 'destroy'])->name('logout');

    // Pintu masuk bersama: manager diarahkan ke dashboard, karyawan ke portal.
    Route::get('beranda', function () {
        return auth()->user()->isManagement()
            ? redirect()->route('dashboard')
            : redirect()->route('karyawan.beranda');
    })->name('beranda');

    // ------------------------------------------------------------- MANAJER

    Route::middleware(EnsureUserIsManagement::class)->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('aktivitas', ScanActivityController::class)->name('aktivitas');
        Route::get('laporan', [ReportController::class, 'index'])->name('laporan');
        Route::get('laporan/excel', [ReportController::class, 'excel'])->name('laporan.excel');

        Route::prefix('manajer')->name('manajer.')->group(function () {
            // Roster
            Route::get('roster', [RosterController::class, 'index'])->name('roster.index');
            Route::post('roster', [RosterController::class, 'store'])->name('roster.store');
            Route::get('roster/{roster}', [RosterController::class, 'show'])->name('roster.show');
            Route::post('roster/{roster}/generate', [RosterController::class, 'generate'])->name('roster.generate');
            Route::post('roster/{roster}/terbitkan', [RosterController::class, 'publish'])->name('roster.publish');
            Route::post('roster/{roster}/jadwal', [RosterController::class, 'assign'])->name('roster.assign');

            // Pengajuan
            Route::get('pengajuan', [RequestApprovalController::class, 'index'])->name('pengajuan.index');
            Route::get('pengajuan/{request}', [RequestApprovalController::class, 'show'])->name('pengajuan.show');
            Route::post('pengajuan/{request}/setujui', [RequestApprovalController::class, 'approve'])->name('pengajuan.approve');
            Route::post('pengajuan/{request}/tolak', [RequestApprovalController::class, 'reject'])->name('pengajuan.reject');

            // Lembur: manager membuat untuk beberapa karyawan sekaligus, lalu
            // mengesahkan realisasinya setelah benar-benar dikerjakan.
            Route::get('lembur', [RequestApprovalController::class, 'overtime'])->name('lembur.index');
            Route::post('lembur', [RequestApprovalController::class, 'storeOvertime'])->name('lembur.store');
            Route::post('lembur/{record}/konfirmasi', [RequestApprovalController::class, 'confirmOvertime'])->name('lembur.confirm');

            // Payroll
            Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
            Route::post('payroll', [PayrollController::class, 'store'])->name('payroll.store');
            Route::get('payroll/{period}', [PayrollController::class, 'show'])->name('payroll.show');
            Route::post('payroll/{period}/hitung', [PayrollController::class, 'generate'])->name('payroll.generate');
            Route::post('payroll/{period}/setujui', [PayrollController::class, 'approve'])->name('payroll.approve');
            Route::post('payroll/{period}/kunci', [PayrollController::class, 'lock'])->name('payroll.lock');
            Route::post('payroll/{period}/buka', [PayrollController::class, 'reopen'])->name('payroll.reopen');
            Route::post('payroll/{period}/entri', [PayrollController::class, 'storeEntry'])->name('payroll.entry');
            Route::get('slip/{payslip}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

            // Karyawan & divisi
            Route::get('karyawan', [EmployeeController::class, 'index'])->name('karyawan.index');
            Route::get('karyawan/{employee}', [EmployeeController::class, 'show'])->name('karyawan.show');
            Route::post('karyawan/{employee}/divisi', [EmployeeController::class, 'syncDivisions'])->name('karyawan.divisi');
            Route::post('karyawan/{employee}/absensi', [EmployeeController::class, 'updateTracking'])->name('karyawan.absensi');
            Route::post('karyawan/{employee}/sandi', [EmployeeController::class, 'resetPassword'])->name('karyawan.sandi');
            Route::post('karyawan/{employee}/username', [EmployeeController::class, 'updateUsername'])->name('karyawan.username');

            // Aturan & setelan yang bisa diubah manager
            Route::get('aturan', [RuleController::class, 'index'])->name('aturan.index');
            Route::post('aturan/{ruleSet}/tarif', [RuleController::class, 'updateTiers'])->name('aturan.tier');
            Route::post('setelan', [RuleController::class, 'updateSettings'])->name('setelan.update');

            // Audit
            Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        });
    });

    // ------------------------------------------------------------ KARYAWAN

    Route::middleware(EnsureUserIsEmployee::class)->prefix('karyawan')->name('karyawan.')->group(function () {
        Route::get('/', [EmployeePortalController::class, 'index'])->name('beranda');
        Route::get('jadwal', [EmployeePortalController::class, 'roster'])->name('jadwal');
        Route::get('absensi', [EmployeePortalController::class, 'attendance'])->name('absensi');

        Route::get('pengajuan', [EmployeeRequestController::class, 'index'])->name('pengajuan.index');
        Route::get('pengajuan/baru/{type}', [EmployeeRequestController::class, 'create'])->name('pengajuan.create');
        Route::post('pengajuan/baru/{type}', [EmployeeRequestController::class, 'store'])->name('pengajuan.store');
        Route::get('pengajuan/{request}', [EmployeeRequestController::class, 'show'])->name('pengajuan.show');
        Route::post('pengajuan/{request}/batal', [EmployeeRequestController::class, 'cancel'])->name('pengajuan.cancel');
        Route::post('pengajuan/{request}/jawab', [EmployeeRequestController::class, 'respond'])->name('pengajuan.respond');

        Route::get('lembur', [EmployeePortalController::class, 'overtime'])->name('lembur.index');
        Route::post('lembur/aktivasi', [EmployeeRequestController::class, 'activateOvertime'])->name('lembur.aktivasi');

        Route::get('slip', [PayslipController::class, 'index'])->name('slip.index');
        Route::get('slip/{payslip}', [PayslipController::class, 'show'])->name('slip.show');
    });
});
