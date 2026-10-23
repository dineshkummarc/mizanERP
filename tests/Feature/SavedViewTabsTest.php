<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class SavedViewTabsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_favorite_view_appears_in_bar_data(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);

        TableView::create([
            'user_id' => $user->id,
            'resource' => \App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource::class,
            'name' => 'My favourite',
            'is_favorite' => true,
            'state' => ['search' => 'ACME'],
        ]);

        $bar = (new ListPayslips)->getViewsBarData();

        $this->assertCount(1, $bar['favorites']);
        $this->assertSame('My favourite', $bar['favorites'][0]['name']);
    }
}
