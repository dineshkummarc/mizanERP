<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Filament\Widgets\SalaryTaxCalculator;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Services\TaxCalculatorService;
use Livewire\Livewire;
use Tests\AccountingTestCase;

/**
 * The dashboard salary calculator: wiring, not arithmetic — the slab maths is
 * TaxCalculatorTest's to pin, and this widget's whole promise is that it calls
 * that same service rather than growing a second implementation.
 */
class SalaryTaxCalculatorWidgetTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A member of staff: the widget is gated on PayslipView, which every role starts with.
        $this->actingAs($this->makeUser('Employee', 'staff@test.local'));
    }

    public function test_it_answers_with_the_payslip_calculators_own_figures(): void
    {
        $component = Livewire::test(SalaryTaxCalculator::class)
            ->set('monthly', '250,000');

        $result = $component->instance()->compute();

        $expectedAnnual = app(TaxCalculatorService::class)->annualTax(3_000_000, $this->fiscalYear->id);

        $this->assertSame(3_000_000.0, $result['annual']);
        $this->assertSame($expectedAnnual, $result['annual_tax']);
        $this->assertSame(round($expectedAnnual / 12, 2), $result['monthly_tax']);
        $this->assertSame(round(250_000 - $expectedAnnual / 12, 2), $result['take_home']);
        $this->assertFalse($result['no_slabs']);
    }

    public function test_blank_or_junk_input_answers_zero_not_an_error(): void
    {
        $widget = new SalaryTaxCalculator;

        $widget->monthly = null;
        $this->assertSame(0.0, $widget->compute()['monthly']);

        $widget->monthly = 'abc';
        $this->assertSame(0.0, $widget->compute()['monthly']);
    }

    public function test_a_year_without_slabs_says_so_rather_than_answering_zero(): void
    {
        SalarySlab::query()->delete();

        $widget = new SalaryTaxCalculator;
        $widget->monthly = '250000';

        $this->assertTrue($widget->compute()['no_slabs']);
    }

    public function test_every_member_of_staff_may_see_it(): void
    {
        // Gated on PayslipView, which every role starts with — so an employee sees it, and only a user
        // holding nothing at all does not (the rule every widget obeys, DashboardWidgetRulesTest). The
        // module-off case is WidgetBelongsToModule's, covered where every widget's gating is.
        $this->assertTrue(SalaryTaxCalculator::canView());

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->assertFalse(SalaryTaxCalculator::canView());
    }
}
