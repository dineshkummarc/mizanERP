<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The salary month picker on both bank-file pages.
 *
 * It used to list only the months that already had payslips in whichever fiscal
 * year happened to be flagged active, so a fresh year offered one option or
 * none, and no file could be produced for any other period.
 */
class SalaryMonthSelectionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'bankfile@test.local'));
        $this->setCurrentTenant();
    }

    private function payslipFor(string $month, FiscalYear $year): Payslip
    {
        $employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'employee_id' => 'EMP-'.fake()->unique()->numberBetween(100, 999),
            'gender' => 'Male',
            'is_active' => true,
        ]);

        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $year->id,
            'month' => $month,
            'basic_wage' => 100000,
        ]);
    }

    /** @return array<string, string> */
    private function monthOptions(string $page): array
    {
        return Livewire::test($page)->instance()->monthOptions();
    }

    public function test_all_twelve_months_are_offered_even_with_no_payslips(): void
    {
        foreach ([SalaryBankFile::class, BankPaymentFile::class, FbrTaxFile::class] as $page) {
            $options = $this->monthOptions($page);

            $this->assertCount(12, $options, $page.' should offer a full year');
            $this->assertArrayHasKey('July', $options);
            $this->assertArrayHasKey('June', $options);
            $this->assertArrayHasKey('December', $options);
        }
    }

    public function test_the_months_run_in_fiscal_order_not_calendar_order(): void
    {
        // The seeded years run July → June.
        $months = array_keys($this->monthOptions(SalaryBankFile::class));

        $this->assertSame('July', $months[0]);
        $this->assertSame('August', $months[1]);
        $this->assertSame('January', $months[6]);
        $this->assertSame('June', $months[11]);
    }

    public function test_labels_carry_the_calendar_year_so_july_is_unambiguous(): void
    {
        $year = FiscalYear::where('name', '2026-2027')->firstOrFail();

        $options = Livewire::test(SalaryBankFile::class)
            ->set('data.fiscal_year_id', $year->id)
            ->instance()
            ->monthOptions();

        // July belongs to the opening calendar year, January to the closing one.
        $this->assertStringContainsString('2026', $options['July']);
        $this->assertStringContainsString('2027', $options['January']);
    }

    public function test_a_month_with_payslips_is_flagged_in_its_label(): void
    {
        $year = FiscalYear::where('name', '2026-2027')->firstOrFail();
        $this->payslipFor('September', $year);
        $this->payslipFor('September', $year);

        $options = Livewire::test(SalaryBankFile::class)
            ->set('data.fiscal_year_id', $year->id)
            ->instance()
            ->monthOptions();

        $this->assertStringContainsString('2 payslips', $options['September']);
        $this->assertStringNotContainsString('payslips', $options['October']);
    }

    public function test_switching_the_fiscal_year_reloads_that_years_counts(): void
    {
        $current = FiscalYear::where('name', '2026-2027')->firstOrFail();
        $previous = FiscalYear::where('name', '2025-2026')->firstOrFail();

        $this->payslipFor('August', $previous);

        $component = Livewire::test(SalaryBankFile::class)->set('data.fiscal_year_id', $current->id);
        $this->assertStringNotContainsString('payslips', $component->instance()->monthOptions()['August']);

        $component->set('data.fiscal_year_id', $previous->id);
        $this->assertStringContainsString('1 payslips', $component->instance()->monthOptions()['August']);
    }

    /**
     * The local database has two fiscal years flagged active at once, which the
     * old `where('is_active', true)->first()` resolved to the older one — so the
     * page silently worked in the wrong year. The date now decides.
     */
    public function test_the_year_containing_today_wins_over_the_first_active_row(): void
    {
        FiscalYear::query()->update(['is_active' => true]);

        $expected = FiscalYear::containing(now()->toDateString());
        $this->assertNotNull($expected, 'a seeded year should contain today');

        foreach ([SalaryBankFile::class, BankPaymentFile::class, FbrTaxFile::class] as $page) {
            $this->assertSame(
                $expected->id,
                Livewire::test($page)->instance()->fiscalYear()->id,
                $page.' should work in the year containing today'
            );
        }
    }

    /**
     * The FBR file exports only payslips with withholding tax, so its month
     * labels count those rather than every payslip — a month labelled with no
     * count really does produce an empty file.
     */
    public function test_the_fbr_month_labels_count_only_taxed_payslips(): void
    {
        $year = FiscalYear::where('name', '2026-2027')->firstOrFail();

        $taxed = $this->payslipFor('October', $year);
        // Written straight to the column: Payslip recalculates withholding_tax
        // from the salary settings on every save, so a model update would be
        // overwritten before it lands.
        Payslip::whereKey($taxed->id)->update(['withholding_tax' => 5000]);
        $this->payslipFor('November', $year);   // no tax deducted

        $options = Livewire::test(FbrTaxFile::class)
            ->set('data.fiscal_year_id', $year->id)
            ->instance()
            ->monthOptions();

        $this->assertStringContainsString('1 with tax', $options['October']);
        $this->assertStringNotContainsString('with tax', $options['November']);
    }

    public function test_the_fbr_page_offers_a_tax_month_even_with_no_payslips_at_all(): void
    {
        $component = Livewire::test(FbrTaxFile::class);

        $this->assertCount(12, $component->instance()->monthOptions());
        $component->assertSet('data.month', now()->format('F'));
        $component->assertSuccessful();
    }

    public function test_the_default_month_is_the_current_one(): void
    {
        Livewire::test(SalaryBankFile::class)
            ->assertSet('data.month', now()->format('F'));
    }

    public function test_selecting_an_empty_month_produces_no_rows_without_erroring(): void
    {
        $component = Livewire::test(SalaryBankFile::class)
            ->set('data.month', 'February');

        $this->assertSame([], $component->instance()->getPayments());
        $component->assertSuccessful();
    }
}
