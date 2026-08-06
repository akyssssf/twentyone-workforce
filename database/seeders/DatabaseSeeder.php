<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Master data selalu dijalankan: tanpanya sistem tidak bisa dipakai
        // sama sekali (tidak ada divisi, tidak ada aturan, tidak ada shift).
        $this->call(MasterDataSeeder::class);

        // Data contoh hanya di lingkungan non-produksi. Di produksi, karyawan
        // diinput manager lewat menu Karyawan.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
