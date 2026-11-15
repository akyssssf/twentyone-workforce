<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EditDivisiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_edit_divisi_menjadikannya_divisi_utama_tanpa_menghapus_yang_lain(): void
    {
        $kasir = Division::create(['code' => 'kasir', 'name' => 'Kasir', 'color' => '#000', 'sort_order' => 1]);
        $cleaning = Division::create(['code' => 'cleaning', 'name' => 'Cleaning Service', 'color' => '#111', 'sort_order' => 2]);
        $e = Employee::factory()->create(['pin_device' => '24']);
        $e->divisions()->attach($kasir->id, ['is_primary' => true, 'competency_level' => 3]);

        $this->assertSame(0, Artisan::call('employee:edit', ['pin' => '24', '--divisi' => 'cleaning']));

        $e = $e->fresh()->load('divisions');
        $this->assertSame('cleaning', $e->primaryDivision()->code);
        $this->assertCount(2, $e->divisions);
    }

    public function test_divisi_tidak_dikenal_ditolak(): void
    {
        Employee::factory()->create(['pin_device' => '24']);

        $this->assertSame(1, Artisan::call('employee:edit', ['pin' => '24', '--divisi' => 'ngawur']));
    }
}
