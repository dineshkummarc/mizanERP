<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\RegisterEntryService;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\TransactionTypeSeeder;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\AccountingTestCase;

/**
 * Editing and deleting register rows.
 *
 * The register is the one surface in this system that restates a posted entry in
 * place instead of reversing it, so these tests pin down both halves of that
 * bargain: the ledger arithmetic stays exact, and anything owned by another
 * document is refused.
 */
class RegisterEntryEditTest extends AccountingTestCase
{
    private RegisterEntryService $register;

    private Account $bank;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        $this->register = app(RegisterEntryService::class);
        $this->bank = Account::where('code', '1100')->firstOrFail();
        $this->expense = Account::where('code', '5700')->firstOrFail();
    }

    private function bookOut(float $amount = 1000, string $description = 'Office rent'): JournalEntry
    {
        return $this->register->bookRow($this->bank, $this->expense, [
            'date' => '2026-07-10',
            'description' => $description,
            'direction' => 'out',
            'amount' => $amount,
        ]);
    }

    private function balances(): array
    {
        return [
            'bank' => round((float) $this->bank->refresh()->balance, 2),
            'expense' => round((float) $this->expense->refresh()->balance, 2),
        ];
    }

    public function test_editing_the_amount_moves_both_balances_by_the_delta(): void
    {
        $entry = $this->bookOut(1000);
        $before = $this->balances();

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Office rent',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 1500,
        ]);

        $after = $this->balances();

        // 500 more out of the bank, 500 more expense.
        $this->assertSame(round($before['bank'] - 500, 2), $after['bank']);
        $this->assertSame(round($before['expense'] + 500, 2), $after['expense']);
        $this->assertSame(2, $entry->refresh()->lines()->count());
        $this->assertTrue($entry->isBalanced());
    }

    public function test_editing_the_description_date_and_num_leaves_balances_untouched(): void
    {
        $entry = $this->bookOut(1000);
        $before = $this->balances();

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-15',
            'description' => 'Office rent — July',
            'num' => 'CHQ-42',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 1000,
        ]);

        $entry->refresh();

        $this->assertSame($before, $this->balances());
        $this->assertSame('2026-07-15', $entry->entry_date->toDateString());
        $this->assertSame('Office rent — July', $entry->memo);
        $this->assertSame('CHQ-42', $entry->reference);
        $this->assertSame('Office rent — July', $entry->lines()->first()->description);
    }

    public function test_flipping_the_direction_reverses_the_effect(): void
    {
        $entry = $this->bookOut(1000);
        $before = $this->balances();

        // Was money out; correct it to money in for the same amount.
        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Refund received',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'in',
            'amount' => 1000,
        ]);

        $after = $this->balances();

        $this->assertSame(round($before['bank'] + 2000, 2), $after['bank']);
        $this->assertSame(round($before['expense'] - 2000, 2), $after['expense']);
    }

    public function test_changing_the_transfer_account_moves_the_balance_to_the_new_one(): void
    {
        $entry = $this->bookOut(1000);
        $other = Account::where('code', '5800')->firstOrFail();

        $expenseBefore = round((float) $this->expense->refresh()->balance, 2);
        $otherBefore = round((float) $other->refresh()->balance, 2);

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Fuel, not rent',
            'transfer_account_id' => $other->id,
            'direction' => 'out',
            'amount' => 1000,
        ]);

        $this->assertSame(round($expenseBefore - 1000, 2), round((float) $this->expense->refresh()->balance, 2));
        $this->assertSame(round($otherBefore + 1000, 2), round((float) $other->refresh()->balance, 2));
    }

    public function test_deleting_unwinds_both_balances_and_removes_the_entry(): void
    {
        $before = $this->balances();
        $entry = $this->bookOut(1000);
        $id = $entry->id;

        $this->assertNotSame($before, $this->balances());

        $this->register->deleteRow($entry, $this->bank);

        $this->assertSame($before, $this->balances());
        $this->assertDatabaseMissing('journal_entries', ['id' => $id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $id]);
    }

    public function test_a_deletion_is_recorded_in_the_audit_log(): void
    {
        $entry = $this->bookOut(1000, 'Rent to delete');
        $number = $entry->entry_number;

        $this->register->deleteRow($entry, $this->bank);

        $activity = Activity::where('event', 'deleted_from_register')->sole();
        $properties = json_encode($activity->properties);

        $this->assertStringContainsString($number, $properties);
        $this->assertStringContainsString('Rent to delete', $properties);
        $this->assertStringContainsString('1000', $properties);
    }

    public function test_an_edit_records_before_and_after(): void
    {
        $entry = $this->bookOut(1000, 'Before text');

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'After text',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 1200,
        ]);

        $properties = json_encode(
            Activity::where('event', 'restated_from_register')->sole()->properties
        );

        $this->assertStringContainsString('Before text', $properties);
        $this->assertStringContainsString('After text', $properties);
    }

    public function test_a_reconciled_row_cannot_be_edited_or_deleted(): void
    {
        $entry = $this->bookOut(1000);
        $entry->lines()->first()->update(['reconciled_at' => now()]);

        $this->assertStringContainsString('reconciled', $this->register->immutableReason($entry->refresh()));
        $this->assertFalse($this->register->isEditableFromRegister($entry));

        $this->expectException(InvalidArgumentException::class);
        $this->register->deleteRow($entry, $this->bank);
    }

    public function test_a_row_owned_by_a_petty_cash_voucher_cannot_be_edited(): void
    {
        $entry = $this->bookOut(500);

        PettyCashVoucher::create([
            'date' => '2026-07-10',
            'details' => 'Cleaning',
            'amount' => 500,
            'transaction_type_id' => TransactionType::byCode('cleaning')->id,
            'journal_entry_id' => $entry->id,
        ]);

        $reason = $this->register->immutableReason($entry);

        $this->assertStringContainsString('petty cash voucher', $reason);
        $this->expectException(InvalidArgumentException::class);
        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Nope',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 900,
        ]);
    }

    public function test_a_row_owned_by_a_payment_cannot_be_edited(): void
    {
        $entry = $this->bookOut(700);

        $beneficiary = Beneficiary::create(['name' => 'Landlord', 'is_active' => true]);

        Payment::create([
            'payable_type' => Beneficiary::class,
            'payable_id' => $beneficiary->id,
            'transaction_type_id' => TransactionType::byCode('rent')->id,
            'amount' => 700,
            'details' => 'July rent',
            'value_date' => '2026-07-10',
            'status' => Payment::STATUS_DRAFT,
            'journal_entry_id' => $entry->id,
        ]);

        $this->assertStringContainsString('payment', $this->register->immutableReason($entry));
    }

    public function test_a_payroll_sourced_row_cannot_be_edited(): void
    {
        $entry = $this->bookOut(900);
        $entry->update(['source_type' => Payslip::class, 'source_id' => 1]);

        $this->assertStringContainsString('Payslip', $this->register->immutableReason($entry->refresh()));
    }

    public function test_a_split_entry_cannot_be_edited(): void
    {
        $entry = $this->bookOut(1000);
        $entry->lines()->create([
            'account_id' => $this->expense->id,
            'debit_amount' => 0,
            'credit_amount' => 0,
            'description' => 'third line',
        ]);

        $this->assertStringContainsString('split', $this->register->immutableReason($entry->refresh()));
    }

    public function test_a_reversing_entry_cannot_be_edited(): void
    {
        $entry = $this->bookOut(1000);
        $reversal = app(JournalEntryService::class)->reverse($entry, $this->makeUser('Manager', 'rev@test.local'));

        $this->assertStringContainsString('reversing', $this->register->immutableReason($reversal));
    }

    public function test_the_transfer_account_may_not_equal_the_register_account(): void
    {
        $entry = $this->bookOut(1000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ');

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Same account',
            'transfer_account_id' => $this->bank->id,
            'direction' => 'out',
            'amount' => 1000,
        ]);
    }

    public function test_a_non_positive_amount_is_refused(): void
    {
        $entry = $this->bookOut(1000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Zero',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 0,
        ]);
    }

    public function test_the_computed_ledger_agrees_with_the_stored_balance_after_edits(): void
    {
        $entry = $this->bookOut(1000);

        $this->register->updateRow($entry, $this->bank, [
            'date' => '2026-07-10',
            'description' => 'Adjusted',
            'transfer_account_id' => $this->expense->id,
            'direction' => 'out',
            'amount' => 1750.25,
        ]);

        // registerRows() computes balances from the lines; Account::balance is
        // maintained separately. Both must tell the same story.
        $ledger = $this->register->registerRows($this->bank);

        $this->assertSame(
            round((float) $this->bank->refresh()->balance, 2),
            round($ledger['closing_balance'], 2)
        );
    }
}
