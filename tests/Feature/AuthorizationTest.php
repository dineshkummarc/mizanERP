<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;

class AuthorizationTest extends AccountingTestCase
{
    public function test_accountant_cannot_approve_or_post(): void
    {
        $accountant = $this->makeUser('Accountant', 'acct@test.local');
        $entry = new JournalEntry(['status' => 'pending_approval']);

        $this->assertFalse($accountant->can('approve', $entry));
        $this->assertFalse($accountant->can('post', new JournalEntry(['status' => 'approved'])));
        $this->assertTrue($accountant->can('create', JournalEntry::class));
    }

    public function test_manager_can_approve_but_not_own_entries(): void
    {
        $manager = $this->makeUser('Manager', 'mgr@test.local');

        $others = new JournalEntry(['status' => 'pending_approval', 'created_by' => 999]);
        $own = new JournalEntry(['status' => 'pending_approval', 'created_by' => $manager->id]);

        $this->assertTrue($manager->can('approve', $others));
        $this->assertFalse($manager->can('approve', $own));
    }

    public function test_posted_entries_are_not_editable(): void
    {
        $manager = $this->makeUser('Manager', 'mgr2@test.local');
        $posted = new JournalEntry(['status' => 'posted', 'is_posted' => true]);

        $this->assertFalse($manager->can('update', $posted));
        $this->assertFalse($manager->can('delete', $posted));
        $this->assertTrue($manager->can('reverse', $posted));
    }

    public function test_only_ceo_can_delete_accounts_and_only_unused_ones(): void
    {
        $manager = $this->makeUser('Manager', 'mgr3@test.local');
        $ceo = $this->makeUser('CEO', 'ceo@test.local');

        $leaf = Account::where('code', '5100')->firstOrFail();
        $group = Account::where('code', '5000')->firstOrFail();

        $this->assertFalse($manager->can('delete', $leaf));
        $this->assertTrue($ceo->can('delete', $leaf));
        // group has children -> not deletable even by CEO
        $this->assertFalse($ceo->can('delete', $group));
    }

    public function test_employee_sees_only_own_payslip(): void
    {
        $employeeUser = $this->makeUser('Employee', 'own@test.local');
        $otherUser = $this->makeUser('Employee', 'other@test.local');

        $employee = Employee::create([
            'user_id' => $employeeUser->id, 'employee_id' => 'EMP-A',
            'phone' => '1', 'gender' => 'Male', 'is_active' => 1,
        ]);

        $payslip = new Payslip(['employee_id' => $employee->id]);
        $payslip->setRelation('employee', $employee);

        $this->assertTrue($employeeUser->can('view', $payslip));
        $this->assertFalse($otherUser->can('view', $payslip));
    }

    public function test_comment_author_can_edit_until_reply_or_resolution(): void
    {
        $author = $this->makeUser('Employee', 'author@test.local');
        $staff = $this->makeUser('Accountant', 'staff@test.local');

        $employee = Employee::create([
            'user_id' => $author->id, 'employee_id' => 'EMP-B',
            'phone' => '1', 'gender' => 'Male', 'is_active' => 1,
        ]);

        $comment = Comment::create([
            'commentable_type' => Payslip::class,
            'commentable_id' => 1,
            'user_id' => $author->id,
            'body' => 'My overtime is missing',
        ]);

        $this->assertTrue($author->can('update', $comment));
        $this->assertFalse($staff->can('update', $comment));
        $this->assertTrue($staff->can('resolve', $comment));
        $this->assertFalse($author->can('resolve', $comment));

        $comment->update(['resolved_at' => now(), 'resolved_by' => $staff->id]);

        $this->assertFalse($author->can('update', $comment->fresh()));
    }

    public function test_audit_logs_are_immutable_for_everyone(): void
    {
        $this->makeUser('Manager', 'mgr4@test.local');

        $account = Account::where('code', '5100')->firstOrFail();
        $account->update(['name' => 'Renamed Salary Expense']);

        $activity = \Spatie\Activitylog\Models\Activity::where('log_name', 'Account')->firstOrFail();

        $manager = User::where('email', 'mgr4@test.local')->firstOrFail();
        $this->assertTrue($manager->can('view', $activity));
        $this->assertFalse($manager->can('update', $activity));
        $this->assertFalse($manager->can('delete', $activity));
    }

    public function test_model_changes_write_audit_rows_with_diff(): void
    {
        $account = Account::where('code', '5100')->firstOrFail();
        $account->update(['name' => 'Changed Name']);

        // whereMorphedTo rather than a literal subject_type: the column holds the
        // model's morph alias, which is deliberately not its current class name.
        $activity = \Spatie\Activitylog\Models\Activity::where('log_name', 'Account')
            ->where('event', 'updated')
            ->whereMorphedTo('subject', $account)
            ->latest('id')
            ->firstOrFail();

        $changes = $activity->changes();
        $this->assertSame('Changed Name', data_get($changes, 'attributes.name'));
        $this->assertSame('Basic Salary Expense', data_get($changes, 'old.name'));
    }
}
