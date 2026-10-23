<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\CreateEmployeeSetting;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\Pages\CreateAnnualTax;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Modules\Projects\Filament\Resources\Projects\Pages\CreateProject;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The employee pickers on the create forms.
 *
 * Every one labels its options with `display_label` ("EMP-1 - Ali Raza"), but a
 * Filament relationship select searches only its title attribute — here
 * `employee_id`. So the dropdown showed names while the search matched codes,
 * and typing a visible name returned nothing.
 *
 * Names live on the landlord `users` table and employees in the tenant one, so
 * the fix resolves ids across the two connections rather than joining them.
 */
class EmployeeSelectSearchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'selects@test.local'));
        $this->setCurrentTenant();
    }

    /** The Select living at a given state path, found by walking the schema. */
    private function selectAt($schema, string $statePath): Select
    {
        foreach ($schema->getFlatComponents(withHidden: true) as $component) {
            if ($component instanceof Select && $component->getStatePath() === $statePath) {
                return $component;
            }
        }

        $this->fail("no Select found at [{$statePath}]");
    }

    private function employee(string $name, string $employeeId, ?string $email = null): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create([
                'name' => $name,
                'email' => $email ?? str($name)->slug().'@test.local',
            ])->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    public function test_searching_by_name_finds_the_employee(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $this->employee('Sara Khan', 'EMP-202');

        $results = EmployeeOptions::search('Ali');

        $this->assertArrayHasKey($ali->id, $results);
        $this->assertCount(1, $results);
        $this->assertSame('EMP-101 - Ali Raza', $results[$ali->id]);
    }

    public function test_searching_by_partial_surname_and_by_email_also_works(): void
    {
        $sara = $this->employee('Sara Khan', 'EMP-202', 'sara.khan@erbium.test');

        $this->assertArrayHasKey($sara->id, EmployeeOptions::search('Khan'));
        $this->assertArrayHasKey($sara->id, EmployeeOptions::search('sara.khan@erbium'));
        $this->assertArrayHasKey($sara->id, EmployeeOptions::search('khan'), 'search should be case-insensitive');
    }

    public function test_searching_by_employee_code_still_works(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $this->employee('Sara Khan', 'EMP-202');

        $this->assertSame([$ali->id => 'EMP-101 - Ali Raza'], EmployeeOptions::search('EMP-101'));
    }

    public function test_an_empty_or_unmatched_search_returns_nothing(): void
    {
        $this->employee('Ali Raza', 'EMP-101');

        $this->assertSame([], EmployeeOptions::search('   '));
        $this->assertSame([], EmployeeOptions::search('zzz-nobody'));
    }

    public function test_results_are_capped(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->employee("Person {$i}", "EMP-{$i}00");
        }

        $this->assertCount(2, EmployeeOptions::search('Person', limit: 2));
    }

    /**
     * The scope must apply to search as well as to the option list, or an
     * employee could reach colleagues outside their downline by typing a name.
     */
    public function test_the_accessible_scope_is_honoured_by_search(): void
    {
        $manager = $this->employee('Team Lead', 'EMP-LEAD');
        $report = $this->employee('Direct Report', 'EMP-REPORT');
        $report->update(['manager_id' => $manager->id]);
        $stranger = $this->employee('Someone Else', 'EMP-OTHER');

        // Sign in as the plain-employee team lead.
        $lead = User::find($manager->user_id);
        $lead->assignRole('Employee');
        $this->actingAs($lead);

        $results = EmployeeOptions::search('e', EmployeeOptions::accessibleScope(), limit: 50);

        $this->assertArrayHasKey($manager->id, $results, 'own record');
        $this->assertArrayHasKey($report->id, $results, 'downline');
        $this->assertArrayNotHasKey($stranger->id, $results, 'outside the downline');
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function createPages(): array
    {
        return [
            'employee setting' => [CreateEmployeeSetting::class],
            'payslip' => [CreatePayslip::class],
            'annual tax' => [CreateAnnualTax::class],
        ];
    }

    #[DataProvider('createPages')]
    public function test_the_create_form_select_returns_the_employee_when_searched_by_name(string $page): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $this->employee('Sara Khan', 'EMP-202');

        $results = $this->selectAt(Livewire::test($page)->instance()->form, 'data.employee_id')
            ->getSearchResults('Ali Raza');

        $this->assertArrayHasKey($ali->id, $results, $page.' should find an employee by name');
    }

    public function test_the_project_manager_selects_search_by_name(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');

        $form = Livewire::test(CreateProject::class)->instance()->form;

        foreach (['data.manager_employee_id', 'data.secondary_employee_id'] as $path) {
            $this->assertArrayHasKey(
                $ali->id,
                $this->selectAt($form, $path)->getSearchResults('Ali'),
                $path.' should find an employee by name'
            );
        }
    }
}
