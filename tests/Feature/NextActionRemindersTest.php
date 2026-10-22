<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\NextAction;
use App\Modules\Crm\Services\NextActionReminders;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Notifications\NextActionDue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The morning digest of next actions — `docs/bexio-gap-plan.md` Phase 2, item 6.
 *
 * The two assertions that carry it: one message per person listing everything (`docs/crms-plan.md`
 * §9), and once per due date rather than every morning until it is done. A snooze is a new due date.
 */
class NextActionRemindersTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const TODAY = '2026-10-22';

    private NextActionReminders $reminders;

    private User $user;

    private Employee $employee;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 07:30:00');

        $this->actingAs($this->makeUser('Administrator', 'admin@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        $this->user = $this->makeUser('Employee', 'ali@test.local');
        $this->employee = Employee::create(['user_id' => $this->user->getKey(), 'employee_id' => 'EMP-1', 'gender' => 'Male']);
        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
        $this->reminders = app(NextActionReminders::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_action_due_today_is_reminded_once_and_not_nagged_afterwards(): void
    {
        $action = $this->action(self::TODAY);

        $this->assertCount(1, $this->reminders->send(self::TODAY));

        Notification::assertSentTo($this->user, NextActionDue::class, fn (NextActionDue $n): bool => $n->actions->count() === 1);
        $this->assertSame(self::TODAY, $action->fresh()->reminded_on->toDateString());

        // The same morning, and every morning after while it stays open: nothing.
        $this->assertCount(0, $this->reminders->send(self::TODAY));
        $this->assertCount(0, $this->reminders->send('2026-10-23'));
        $this->assertCount(0, $this->reminders->send('2026-11-01'));
    }

    public function test_one_digest_lists_everything_due_to_the_same_person(): void
    {
        $this->action(self::TODAY, ['title' => 'Call about the pricing']);
        $this->action('2026-10-20', ['title' => 'Send the brochure']);

        $this->assertCount(2, $this->reminders->send(self::TODAY));

        Notification::assertSentToTimes($this->user, NextActionDue::class, 1);
        Notification::assertSentTo($this->user, NextActionDue::class, fn (NextActionDue $n): bool => $n->actions->count() === 2);
    }

    public function test_a_snooze_is_a_new_due_date(): void
    {
        $action = $this->action(self::TODAY);

        $this->assertCount(1, $this->reminders->send(self::TODAY));

        $action->update(['snoozed_until' => '2026-10-27']);

        $this->assertCount(0, $this->reminders->send('2026-10-24'), 'still snoozed');
        $this->assertCount(1, $this->reminders->send('2026-10-27'), 'the snooze ended, so it is due again');
        $this->assertCount(0, $this->reminders->send('2026-10-28'), 'and reminded once for that date');
    }

    public function test_nothing_is_sent_for_what_cannot_or_need_not_be_reminded(): void
    {
        $this->action(self::TODAY, ['completed_at' => now()]);
        $this->action('2026-10-25');
        $this->action(self::TODAY, ['assignee_employee_id' => null]);

        $nobody = Employee::create(['employee_id' => 'EMP-2', 'gender' => 'Female']);
        $this->action(self::TODAY, ['assignee_employee_id' => $nobody->getKey()]);

        $this->assertCount(0, $this->reminders->send(self::TODAY));
        Notification::assertNothingSent();
    }

    public function test_the_digest_names_each_action_and_who_it_is_about(): void
    {
        $action = $this->action(self::TODAY, ['title' => 'Call about the pricing']);

        $mail = (new NextActionDue(collect([$action->load('subject')])))->toMail($this->user);

        $this->assertSame('One next action falls due today', $mail->subject);
        $this->assertStringContainsString('Call about the pricing — Karachi Textiles', implode(' ', $mail->introLines));
    }

    public function test_a_company_can_reword_the_digest(): void
    {
        EmailTemplate::create(['key' => 'next_action_due', 'subject' => '{count} to do, {employee_name}', 'is_active' => true]);

        $action = $this->action(self::TODAY);

        $mail = (new NextActionDue(collect([$action])))->toMail($this->user);

        $this->assertSame('1 to do, '.$this->user->name, $mail->subject);
    }

    /** @param  array<string, mixed>  $attributes */
    private function action(string $dueOn, array $attributes = []): NextAction
    {
        return $this->customer->nextActions()->create(array_merge([
            'title' => 'Call about the pricing',
            'due_on' => $dueOn,
            'assignee_employee_id' => $this->employee->getKey(),
        ], $attributes));
    }
}
