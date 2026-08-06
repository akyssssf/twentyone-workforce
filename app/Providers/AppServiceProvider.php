<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nama hari dan bulan dalam bahasa Indonesia di seluruh tampilan.
        //
        // Sengaja hanya locale Carbon, bukan APP_LOCALE. Mengubah APP_LOCALE
        // akan ikut mengalihkan pesan validasi Laravel ke berkas terjemahan
        // yang belum ada, dan hasilnya malah kosong.
        Carbon::setLocale('id');
    }
}
