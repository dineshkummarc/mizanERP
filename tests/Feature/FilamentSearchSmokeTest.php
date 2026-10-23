<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\Pages\ListAnnualTaxes;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class FilamentSearchSmokeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();
    }

    public function test_multi_relation_search_runs(): void
    {
        foreach ([ListAnnualTaxes::class, ListEmployeeSettings::class, ListPayslips::class] as $page) {
            Livewire::test($page)
                ->set('tableSearch', 'EMP-1')
                ->assertSuccessful()
                ->set('tableSearch', 'Alice')
                ->assertSuccessful();
        }
    }
}
