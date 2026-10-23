<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\User;
use InvalidArgumentException;
use Tests\AccountingTestCase;

class JournalEntryServiceTest extends AccountingTestCase
{
    private JournalEntryService $service;

    private Account $cash;

    private Account $salaries;

    private User $maker;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(JournalEntryService::class);
        $this->cash = Account::where('code', '1100')->firstOrFail();
        $this->salaries = Account::where('code', '5100')->firstOrFail();
        $this->maker = $this->makeUser('Accountant', 'maker@test.local');
        $this->approver = $this->makeUser('Manager', 'approver@test.local');
    }

    private function makeEntry(float $amount = 1000): JournalEntry
    {
        return $this->service->create(
            ['entry_date' => '2026-07-15', 'created_by' => $this->maker->id, 'fiscal_year_id' => $this->fiscalYear->id],
            [
                ['account_id' => $this->salaries->id, 'debit_amount' => $amount],
                ['account_id' => $this->cash->id, 'credit_amount' => $amount],
            ]
        );
    }

    private function approvedEntry(float $amount = 1000): JournalEntry
    {
        $entry = $this->makeEntry($amount);
        $this->service->submitForApproval($entry);

        return $this->service->approve($entry, $this->approver);
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not balanced');

        $this->service->create(['entry_date' => '2026-07-15'], [
            ['account_id' => $this->salaries->id, 'debit_amount' => 100],
            ['account_id' => $this->cash->id, 'credit_amount' => 90],
        ]);
    }

    public function test_single_line_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(['entry_date' => '2026-07-15'], [
            ['account_id' => $this->salaries->id, 'debit_amount' => 100],
        ]);
    }

    public function test_line_with_both_sides_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(['entry_date' => '2026-07-15'], [
            ['account_id' => $this->salaries->id, 'debit_amount' => 100, 'credit_amount' => 100],
            ['account_id' => $this->cash->id, 'credit_amount' => 0],
        ]);
    }

    public function test_group_account_cannot_receive_lines(): void
    {
        $group = Account::where('code', '5000')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot accept entries');

        $this->service->create(['entry_date' => '2026-07-15'], [
            ['account_id' => $group->id, 'debit_amount' => 100],
            ['account_id' => $this->cash->id, 'credit_amount' => 100],
        ]);
    }

    public function test_inactive_account_cannot_receive_lines(): void
    {
        $this->salaries->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        $this->makeEntry();
    }

    public function test_entry_numbers_are_sequential_per_year(): void
    {
        $first = $this->makeEntry();
        $second = $this->makeEntry();

        $this->assertSame('JE-2026-000001', $first->entry_number);
        $this->assertSame('JE-2026-000002', $second->entry_number);
    }

    public function test_unapproved_entry_cannot_be_posted(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('approved');

        $this->service->post($entry);
    }

    public function test_creator_cannot_approve_own_entry(): void
    {
        $entry = $this->makeEntry();
        $this->service->submitForApproval($entry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('segregation');

        $this->service->approve($entry, $this->maker);
    }

    public function test_rejected_entry_can_be_amended_and_resubmitted(): void
    {
        $entry = $this->makeEntry();
        $this->service->submitForApproval($entry);
        $this->service->reject($entry, $this->approver, 'Wrong amount');

        $this->assertSame('rejected', $entry->fresh()->status);
        $this->assertSame('Wrong amount', $entry->fresh()->rejection_reason);
        $this->assertTrue($entry->fresh()->isEditable());

        $this->service->submitForApproval($entry->fresh());
        $this->assertSame('pending_approval', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->rejection_reason);
    }

    public function test_posting_updates_balances_per_normal_balance_side(): void
    {
        $entry = $this->approvedEntry(1000);
        $this->service->post($entry);

        // salaries is debit-normal: +1000; cash is debit-normal credited: -1000
        $this->assertSame(1000.0, (float) $this->salaries->fresh()->balance);
        $this->assertSame(-1000.0, (float) $this->cash->fresh()->balance);

        $taxPayable = Account::where('code', '2100')->firstOrFail();
        $this->assertSame('credit', $taxPayable->normal_balance);
    }

    public function test_double_posting_is_rejected(): void
    {
        $entry = $this->approvedEntry();
        $this->service->post($entry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already posted');

        $this->service->post($entry->fresh());
    }

    public function test_reversal_restores_balances_and_keeps_original_posted(): void
    {
        $entry = $this->approvedEntry(750);
        $this->service->post($entry);

        $reversal = $this->service->reverse($entry->fresh(), $this->approver);

        $this->assertSame(0.0, (float) $this->salaries->fresh()->balance);
        $this->assertSame(0.0, (float) $this->cash->fresh()->balance);
        $this->assertSame('reversing', $reversal->entry_type);
        $this->assertTrue($reversal->is_posted);
        $this->assertTrue($entry->fresh()->is_posted);
        $this->assertSame($entry->entry_number, $reversal->reference);
    }

    public function test_only_posted_entries_can_be_reversed(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(InvalidArgumentException::class);

        $this->service->reverse($entry);
    }

    public function test_domain_events_are_audit_logged(): void
    {
        $entry = $this->approvedEntry();
        $this->service->post($entry);
        $this->service->reverse($entry->fresh(), $this->approver);

        $events = \Spatie\Activitylog\Models\Activity::where('log_name', 'JournalEntry')
            ->pluck('event');

        foreach (['created_with_lines', 'approved', 'posted', 'reversed'] as $event) {
            $this->assertContains($event, $events->all(), "Missing audit event: {$event}");
        }

        $approval = \Spatie\Activitylog\Models\Activity::where('event', 'approved')->first();
        $this->assertSame($this->approver->id, (int) $approval->causer_id);
    }
}
