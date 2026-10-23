<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\User;
use InvalidArgumentException;
use Tests\AccountingTestCase;

class BankReconciliationTest extends AccountingTestCase
{
    private BankReconciliationService $service;

    private JournalEntryService $entries;

    private Account $cash;

    private Account $revenue;

    private Account $expense;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BankReconciliationService::class);
        $this->entries = app(JournalEntryService::class);
        $this->cash = Account::where('code', '1100')->firstOrFail();
        $this->revenue = Account::where('code', '4100')->firstOrFail();
        $this->expense = Account::where('code', '5100')->firstOrFail();
        $this->approver = $this->makeUser('Manager', 'bank-approver@test.local');
    }

    /** Post an inflow: cash debited, revenue credited. Returns the cash ledger line. */
    private function postInflow(string $date, float $amount): JournalEntryLine
    {
        $entry = $this->entries->create(['entry_date' => $date], [
            ['account_id' => $this->cash->id, 'debit_amount' => $amount],
            ['account_id' => $this->revenue->id, 'credit_amount' => $amount],
        ]);
        $this->postEntry($entry);

        return $entry->lines()->where('account_id', $this->cash->id)->first();
    }

    /** Post an outflow: cash credited, expense debited. Returns the cash ledger line. */
    private function postOutflow(string $date, float $amount): JournalEntryLine
    {
        $entry = $this->entries->create(['entry_date' => $date], [
            ['account_id' => $this->expense->id, 'debit_amount' => $amount],
            ['account_id' => $this->cash->id, 'credit_amount' => $amount],
        ]);
        $this->postEntry($entry);

        return $entry->lines()->where('account_id', $this->cash->id)->first();
    }

    private function postEntry(JournalEntry $entry): void
    {
        $this->entries->submitForApproval($entry);
        $this->entries->approve($entry, $this->approver);
        $this->entries->post($entry);
    }

    private function makeStatement(string $date = '2026-07-31', float $closing = 0): BankStatement
    {
        return BankStatement::create([
            'account_id' => $this->cash->id,
            'statement_date' => $date,
            'opening_balance' => 0,
            'closing_balance' => $closing,
        ]);
    }

    public function test_import_creates_lines_and_marks_statement_in_progress(): void
    {
        $statement = $this->makeStatement();

        $lines = $this->service->import([
            ['transaction_date' => '2026-07-05', 'description' => 'Client payment', 'reference' => 'INV-1', 'amount' => 500],
            ['transaction_date' => '2026-07-06', 'description' => 'Rent', 'reference' => '', 'amount' => -300],
        ], $statement);

        $this->assertCount(2, $lines);
        $this->assertSame(2, $statement->lines()->count());
        $this->assertSame(BankStatement::STATUS_IN_PROGRESS, $statement->fresh()->status);
        $this->assertSame(BankStatementLine::STATUS_UNMATCHED, $lines[0]->match_status);
        $this->assertSame(500.0, (float) $lines[0]->amount);
        $this->assertSame(-300.0, (float) $lines[1]->amount);
    }

    public function test_import_requires_amount_and_date(): void
    {
        $statement = $this->makeStatement();

        $this->expectException(InvalidArgumentException::class);

        $this->service->import([
            ['transaction_date' => '2026-07-05', 'description' => 'x'],
        ], $statement);
    }

    public function test_auto_match_exact_amount_and_date(): void
    {
        $cashLine = $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'description' => 'Client payment', 'amount' => 500],
        ], $statement);

        $matched = $this->service->autoMatch($statement);

        $this->assertSame(1, $matched);
        $line = $statement->lines()->first();
        $this->assertSame(BankStatementLine::STATUS_AUTO_MATCHED, $line->match_status);
        $this->assertSame($cashLine->id, $line->matched_line_id);
        $this->assertNotNull($cashLine->fresh()->reconciled_at);
    }

    public function test_auto_match_within_date_window(): void
    {
        $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-08', 'amount' => 500], // 3 days later
        ], $statement);

        $this->assertSame(1, $this->service->autoMatch($statement));
    }

    public function test_auto_match_skips_outside_date_window(): void
    {
        $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-10', 'amount' => 500], // 5 days later, no reference
        ], $statement);

        $this->assertSame(0, $this->service->autoMatch($statement));
        $this->assertSame(BankStatementLine::STATUS_UNMATCHED, $statement->lines()->first()->match_status);
    }

    public function test_auto_match_leaves_ambiguous_ties_unmatched(): void
    {
        $this->postInflow('2026-07-05', 500);
        $this->postInflow('2026-07-06', 500); // same amount, both within window
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
        ], $statement);

        $this->assertSame(0, $this->service->autoMatch($statement));
    }

    public function test_auto_match_by_reference_when_date_out_of_window(): void
    {
        $cashLine = $this->postInflow('2026-07-01', 500);
        $entryNumber = $cashLine->journalEntry->entry_number;
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-20', 'reference' => "Payment {$entryNumber}", 'amount' => 500],
        ], $statement);

        $this->assertSame(1, $this->service->autoMatch($statement));
        $this->assertSame(BankStatementLine::STATUS_AUTO_MATCHED, $statement->lines()->first()->match_status);
    }

    public function test_outflow_matches_on_negative_amount(): void
    {
        $this->postOutflow('2026-07-05', 300);
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => -300],
        ], $statement);

        $this->assertSame(1, $this->service->autoMatch($statement));
    }

    public function test_manual_match_and_unmatch(): void
    {
        $cashLine = $this->postInflow('2026-07-05', 777);
        $statement = $this->makeStatement();
        [$line] = $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 777],
        ], $statement);

        $this->service->match($line, $cashLine);
        $this->assertSame(BankStatementLine::STATUS_MANUALLY_MATCHED, $line->fresh()->match_status);
        $this->assertNotNull($cashLine->fresh()->reconciled_at);

        $this->service->unmatch($line->fresh());
        $this->assertSame(BankStatementLine::STATUS_UNMATCHED, $line->fresh()->match_status);
        $this->assertNull($line->fresh()->matched_line_id);
        $this->assertNull($cashLine->fresh()->reconciled_at);
    }

    public function test_reconciled_lines_excluded_from_rematching(): void
    {
        $cashLine = $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement();
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
        ], $statement);
        $this->service->autoMatch($statement);

        // A second statement with an identical line must not re-grab the reconciled ledger line.
        $statement2 = $this->makeStatement('2026-08-31');
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
        ], $statement2);

        $this->assertSame(0, $this->service->autoMatch($statement2));
    }

    public function test_exclude_line(): void
    {
        $statement = $this->makeStatement();
        [$line] = $this->service->import([
            ['transaction_date' => '2026-07-05', 'description' => 'Bank fee', 'amount' => -25],
        ], $statement);

        $this->service->exclude($line);

        $this->assertSame(BankStatementLine::STATUS_EXCLUDED, $line->fresh()->match_status);
        $this->assertTrue($statement->fresh()->isFullyMatched());
    }

    public function test_complete_blocked_while_unmatched_lines_remain(): void
    {
        $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement('2026-07-31', 500);
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
        ], $statement);
        // not matched yet

        $this->expectException(InvalidArgumentException::class);
        $this->service->complete($statement, $this->approver);
    }

    public function test_complete_blocked_when_balances_disagree(): void
    {
        $this->postInflow('2026-07-05', 500);
        $statement = $this->makeStatement('2026-07-31', 999); // wrong closing balance
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
        ], $statement);
        $this->service->autoMatch($statement);

        $this->expectException(InvalidArgumentException::class);
        $this->service->complete($statement, $this->approver);
    }

    public function test_complete_succeeds_and_locks_statement(): void
    {
        $this->postInflow('2026-07-05', 500);
        $this->postOutflow('2026-07-10', 300);
        // ledger balance as of 2026-07-31 = 500 - 300 = 200
        $statement = $this->makeStatement('2026-07-31', 200);
        $this->service->import([
            ['transaction_date' => '2026-07-05', 'amount' => 500],
            ['transaction_date' => '2026-07-10', 'amount' => -300],
        ], $statement);
        $this->service->autoMatch($statement);

        $this->assertSame(200.0, $this->service->ledgerBalance($statement));

        $completed = $this->service->complete($statement, $this->approver);

        $this->assertSame(BankStatement::STATUS_COMPLETED, $completed->status);
        $this->assertSame($this->approver->id, $completed->completed_by);
        $this->assertNotNull($completed->completed_at);

        // Locked: no more unmatching.
        $this->expectException(InvalidArgumentException::class);
        $this->service->unmatch($statement->lines()->first());
    }

    public function test_policy_gates_import_match_complete(): void
    {
        $accountant = $this->makeUser('Accountant', 'bank-acct@test.local');
        $manager = $this->makeUser('Manager', 'bank-mgr@test.local');
        $statement = $this->makeStatement();

        $this->assertTrue($accountant->can('import', $statement));
        $this->assertTrue($accountant->can('match', $statement));
        $this->assertFalse($accountant->can('complete', $statement));

        $this->assertTrue($manager->can('match', $statement));
        $this->assertTrue($manager->can('complete', $statement));
    }
}
