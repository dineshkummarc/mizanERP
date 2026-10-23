<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\FiscalYear;
use App\Support\ModuleMap;
use Tests\AccountingTestCase;

/**
 * Closing a year rolls its profit or loss into Retained Earnings.
 *
 * Income and expense accounts measure one period only, so a close zeroes them
 * and books the net to equity, which carries forward. Without that, a new year
 * would open with last year's revenue still on the books.
 */
class FiscalYearRollForwardTest extends AccountingTestCase
{
    private function service(): FiscalYearClosingService
    {
        return app(FiscalYearClosingService::class);
    }

    private function year(): FiscalYear
    {
        $this->fiscalYear->forceFill([
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ])->save();

        return $this->fiscalYear->refresh();
    }

    /** Posts a balanced entry: debit one account, credit another. */
    private function postEntry(string $debitCode, string $creditCode, float $amount, string $date = '2026-08-15'): void
    {
        $service = app(JournalEntryService::class);

        $entry = $service->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Activity'],
            [
                ['account_id' => Account::where('code', $debitCode)->value('id'), 'debit_amount' => $amount],
                ['account_id' => Account::where('code', $creditCode)->value('id'), 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $service->post($entry);
    }

    private function balance(string $code, string $asOf = '2027-06-30'): float
    {
        $row = collect(app(FinancialReportService::class)->trialBalance($asOf)['sections'])
            ->flatMap(fn (array $section) => $section['rows'])
            ->firstWhere('code', $code);

        return $row ? round((float) $row['credit'] - (float) $row['debit'], 2) : 0.0;
    }

    private function admin(string $email = 'roll@test.local')
    {
        return $this->makeUser('Administrator', $email);
    }

    public function test_a_profitable_year_credits_retained_earnings_and_zeroes_income(): void
    {
        // Revenue 100,000 against expenses 40,000 → profit 60,000.
        $this->postEntry('1100', '4100', 100000);
        $this->postEntry('5100', '1100', 40000);

        $this->assertSame(100000.0, $this->balance('4100'), 'income sits on the credit side');

        $this->service()->close($this->year(), $this->admin());

        $this->assertSame(0.0, $this->balance('4100'), 'income should be closed out');
        $this->assertSame(0.0, $this->balance('5100'), 'expenses should be closed out');
        $this->assertSame(60000.0, $this->balance(Account::RETAINED_EARNINGS_CODE), 'profit is a credit to equity');
    }

    public function test_a_loss_making_year_debits_retained_earnings(): void
    {
        // Revenue 20,000 against expenses 50,000 → loss 30,000.
        $this->postEntry('1100', '4100', 20000);
        $this->postEntry('5100', '1100', 50000);

        $this->service()->close($this->year(), $this->admin());

        $this->assertSame(0.0, $this->balance('4100'));
        $this->assertSame(0.0, $this->balance('5100'));
        $this->assertSame(-30000.0, $this->balance(Account::RETAINED_EARNINGS_CODE), 'a loss is a debit to equity');
    }

    /** The closing entry is real, balanced double-entry. */
    public function test_the_closing_entry_is_recorded_and_balanced(): void
    {
        $this->postEntry('1100', '4100', 100000);
        $this->postEntry('5100', '1100', 40000);

        $year = $this->year();
        $this->service()->close($year, $this->admin());

        $entry = $this->service()->closingEntry($year);

        $this->assertNotNull($entry);
        $this->assertSame('closing', $entry->entry_type);
        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($year->end_date->toDateString(), $entry->entry_date->toDateString(), 'dated at year end');
        // The alias, not the live class name: source_type is deliberately stable
        // across the model moving into a module directory.
        $this->assertSame(ModuleMap::alias(FiscalYear::class), $entry->source_type);
        $this->assertSame($year->getKey(), (int) $entry->source_id);

        // Income, expense and retained earnings — three lines.
        $this->assertCount(3, $entry->lines);
    }

    /** The trial balance must still tie after the roll-forward. */
    public function test_the_books_still_balance_after_closing(): void
    {
        $this->postEntry('1100', '4100', 100000);
        $this->postEntry('5100', '1100', 40000);

        $this->service()->close($this->year(), $this->admin());

        $this->assertTrue(app(FinancialReportService::class)->trialBalance('2027-06-30')['balanced']);
    }

    public function test_a_year_with_no_activity_needs_no_closing_entry(): void
    {
        $year = $this->year();

        $this->service()->close($year, $this->admin());

        $this->assertNull($this->service()->closingEntry($year), 'nothing to roll forward');
        $this->assertTrue($year->refresh()->isClosed());
    }

    /** Income exactly offsetting expenses leaves equity untouched. */
    public function test_a_break_even_year_posts_no_retained_earnings_line(): void
    {
        $this->postEntry('1100', '4100', 50000);
        $this->postEntry('5100', '1100', 50000);

        $year = $this->year();
        $this->service()->close($year, $this->admin());

        $entry = $this->service()->closingEntry($year);

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines, 'no equity line when the net is zero');
        $this->assertSame(0.0, $this->balance(Account::RETAINED_EARNINGS_CODE));
    }

    /**
     * Reopening must undo the roll-forward, or the reopened year would report no
     * activity at all.
     */
    public function test_reopening_reverses_the_roll_forward(): void
    {
        $this->postEntry('1100', '4100', 100000);
        $this->postEntry('5100', '1100', 40000);

        $year = $this->year();
        $admin = $this->admin();

        $this->service()->close($year, $admin);
        $this->assertSame(0.0, $this->balance('4100'));

        $this->service()->reopen($year->refresh(), $admin);

        $this->assertSame(100000.0, $this->balance('4100'), 'income is back');
        $this->assertSame(40000.0, -$this->balance('5100'), 'expenses are back');
        $this->assertSame(0.0, $this->balance(Account::RETAINED_EARNINGS_CODE), 'equity is back to zero');
        $this->assertFalse($year->refresh()->isClosed());
    }

    /** Closing again after a reopen must not double-count the profit. */
    public function test_closing_again_after_a_reopen_rolls_the_same_profit_once(): void
    {
        $this->postEntry('1100', '4100', 100000);
        $this->postEntry('5100', '1100', 40000);

        $year = $this->year();
        $admin = $this->admin();

        $this->service()->close($year, $admin);
        $this->service()->reopen($year->refresh(), $admin);
        $this->service()->close($year->refresh(), $admin);

        $this->assertSame(60000.0, $this->balance(Account::RETAINED_EARNINGS_CODE), 'still 60,000, not 120,000');
        $this->assertSame(0.0, $this->balance('4100'));
        $this->assertTrue(app(FinancialReportService::class)->trialBalance('2027-06-30')['balanced']);
    }

    /** Activity outside the year must not be swept into its close. */
    public function test_activity_from_another_period_is_left_alone(): void
    {
        $this->postEntry('1100', '4100', 100000, '2026-08-15');   // inside
        $this->postEntry('1100', '4100', 25000, '2027-08-15');    // next year

        $this->service()->close($this->year(), $this->admin());

        // As of this year end, income is closed out...
        $this->assertSame(0.0, $this->balance('4100', '2027-06-30'));
        $this->assertSame(60000.0 + 40000.0, $this->balance(Account::RETAINED_EARNINGS_CODE, '2027-06-30'));

        // ...but the later entry survives.
        $this->assertSame(25000.0, $this->balance('4100', '2027-12-31'));
    }
}
