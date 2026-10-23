<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Filament\Resources\Employees\RelationManagers\DirectReportsRelationManager;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class ViewEmployeeSmokeTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_view_page_and_pdf_blade_render(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);

        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-A', 'phone' => '111', 'secondary_phone' => '222',
            'gender' => 'Male', 'is_active' => 1, 'nic' => '123',
        ]);

        Livewire::test(ViewEmployee::class, ['record' => $emp->id])->assertSuccessful();

        // PDF blade renders without error.
        $html = view('pdfs.employee', ['employee' => $emp->load('user', 'bank', 'manager.user')])->render();
        $this->assertStringContainsString('Employee Information', $html);
        $this->assertStringContainsString('222', $html); // secondary phone shown
    }

    public function test_direct_reports_tab_lists_who_reports_here(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);

        $manager = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-M', 'gender' => 'Male', 'is_active' => 1,
        ]);

        // Two reports rather than one: a one-row table never arms the lazy-loading
        // guard, and these rows read `display_label` through the user relation.
        // No logins on purpose — display_label falls back to the name column.
        $reports = collect(['EMP-R1' => 'Report One', 'EMP-R2' => 'Report Two'])
            ->map(fn (string $name, string $code) => Employee::create([
                'employee_id' => $code, 'name' => $name, 'manager_id' => $manager->id,
                'designation' => 'Engineer', 'gender' => 'Male', 'is_active' => 1,
            ]))
            ->values();

        Livewire::test(DirectReportsRelationManager::class, [
            'ownerRecord' => $manager,
            'pageClass' => ViewEmployee::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($reports)
            ->assertSee('Report One')
            ->assertSee('Engineer');
    }
}
