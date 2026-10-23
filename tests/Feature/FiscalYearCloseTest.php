<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\RegisterEntryService;
use App\Modules\Core\Models\FiscalYear;
use Tests\AccountingTestCase;

/**
 * Closing a fiscal year freezes its ledger, and must refuse while the books are
 * knowingly incomplete.
 *
 * The headline guard is Opening Balance Equity: a single opening entry is valid
 * double-entry, so the trial balance ties while half the opening figures are
 * still missing. Closing over that would freeze a period whose numbers are
 * already known to be wrong.
 */
class FiscalYearCloseTest extends AccountingTestCase
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

    /** Leaves Opening Balance Equity holding the credit side. */
    private function halfOpenTheBook(float $amount = 250000): void
    {
        app(RegisterEntryService::class)->bookRow(
            Account::where('code', '1100')->firstOrFail(),
            Account::where('code', Account::OPENING_BALANCE_EQUITY_CODE)->firstOrFail(),
            ['date' => '2026-06-30', 'amount' => $amount, 'direction' => 'in', 'description' => 'Opening balance'],
        );
    }

    private function retireOpeningEquity(float $amount = 250000): void
    {
        app(RegisterEntryService::class)->bookRow(
            Account::where('code', '1100')->firstOrFail(),
            Account::where('code', Account::OPENING_BALANCE_EQUITY_CODE)->firstOrFail(),
            ['date' => '2026-06-30', 'amount' => $amount, 'direction' => 'out', 'description' => 'Retire opening balance equity'],
        );
    }

    public function test_a_clean_year_closes(): void
    {
        $year = $this->year();

        $this->assertSame([], $this->service()->blockers($year));
        $this->assertTrue($this->service()->canClose($year));

        $closed = $this->service()->close($year, $this->makeUser('Administrator', 'closer@test.local'));

        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->closed_at);
    }

    /** The requested guard. */
    public function test_it_refuses_while_opening_balance_equity_is_not_zero(): void
    {
        $this->halfOpenTheBook();
        $year = $this->year();

        $blockers = $this->service()->blockers($year);

        $this->assertNotSame([], $blockers);
        $this->assertStringContainsString('Opening Balance Equity', $blockers[0]);
        $this->assertStringContainsString('250,000.00', $blockers[0]);
        $this->assertFalse($this->service()->canClose($year));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Opening Balance Equity/');
        $this->service()->close($year, $this->makeUser('Administrator', 'blocked@test.local'));
    }

    /** …and the year stays open when the close is refused. */
    public function test_a_refused_close_leaves_the_year_open(): void
    {
        $this->halfOpenTheBook();
        $year = $this->year();

        try {
            $this->service()->close($year, $this->makeUser('Administrator', 'refused@test.local'));
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertFalse($year->refresh()->isClosed());
    }

    public function test_it_closes_once_the_opening_balances_are_squared_away(): void
    {
        $this->halfOpenTheBook();
        $this->retireOpeningEquity();

        $year = $this->year();

        $this->assertSame([], $this->service()->blockers($year));

        $this->service()->close($year, $this->makeUser('Administrator', 'ok@test.local'));

        $this->assertTrue($year->refresh()->isClosed());
    }

    public function test_unposted_entries_in_the_period_also_block_the_close(): void
    {
        $service = app(JournalEntryService::class);

        $service->create(
            ['entry_date' => '2026-08-15', 'entry_type' => 'general', 'memo' => 'Still a draft'],
            [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit_amount' => 500],
                ['account_id' => Account::where('code', '4100')->value('id'), 'credit_amount' => 500],
            ],
        );

        $blockers = $this->service()->blockers($this->year());

        $this->assertNotSame([], $blockers);
        $this->assertStringContainsString('not been posted', implode(' ', $blockers));
    }

    /** Every reason is reported at once rather than one per attempt. */
    public function test_all_blockers_are_reported_together(): void
    {
        $this->halfOpenTheBook();

        app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-15', 'entry_type' => 'general', 'memo' => 'Draft'],
            [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit_amount' => 500],
                ['account_id' => Account::where('code', '4100')->value('id'), 'credit_amount' => 500],
            ],
        );

        $blockers = $this->service()->blockers($this->year());

        $this->assertCount(2, $blockers);
    }

    /** A close that does not stop postings would be decorative. */
    public function test_posting_into_a_closed_year_is_refused(): void
    {
        $year = $this->year();
        $this->service()->close($year, $this->makeUser('Administrator', 'freeze@test.local'));

        $service = app(JournalEntryService::class);

        $entry = $service->create(
            ['entry_date' => '2026-08-15', 'entry_type' => 'general', 'memo' => 'Too late'],
            [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit_amount' => 500],
                ['account_id' => Account::where('code', '4100')->value('id'), 'credit_amount' => 500],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is closed/');
        $service->post($entry);
    }

    public function test_posting_outside_a_closed_year_still_works(): void
    {
        $year = $this->year();
        $this->service()->close($year, $this->makeUser('Administrator', 'outside@test.local'));

        // Dated after the closed year's end.
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2027-08-15', 'entry_type' => 'general', 'memo' => 'Next year'],
            [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit_amount' => 500],
                ['account_id' => Account::where('code', '4100')->value('id'), 'credit_amount' => 500],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $this->assertTrue(app(JournalEntryService::class)->post($entry)->is_posted);
    }

    public function test_reopening_allows_posting_again(): void
    {
        $year = $this->year();
        $admin = $this->makeUser('Administrator', 'reopen@test.local');

        $this->service()->close($year, $admin);
        $this->service()->reopen($year->refresh(), $admin);

        $this->assertFalse($year->refresh()->isClosed());

        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-15', 'entry_type' => 'general', 'memo' => 'After reopen'],
            [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit_amount' => 500],
                ['account_id' => Account::where('code', '4100')->value('id'), 'credit_amount' => 500],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $this->assertTrue(app(JournalEntryService::class)->post($entry)->is_posted);
    }

    public function test_closing_an_already_closed_year_is_refused(): void
    {
        $year = $this->year();
        $admin = $this->makeUser('Administrator', 'twice@test.local');

        $this->service()->close($year, $admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already closed/');
        $this->service()->close($year->refresh(), $admin);
    }

    public function test_a_year_without_dates_cannot_be_closed(): void
    {
        $year = $this->fiscalYear->forceFill(['start_date' => null, 'end_date' => null]);
        $year->save();

        $blockers = $this->service()->blockers($year);

        $this->assertStringContainsString('no start and end date', $blockers[0]);
    }
}
