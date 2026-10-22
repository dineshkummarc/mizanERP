<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Services\OverdueReminderService;
use App\Notifications\InvoiceOverdue;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Chasing overdue invoices — `docs/erpnext-gap-plan.md` Phase 5, item 4.
 *
 * Everything this needed already existed: the ageing query, `EmailTemplate` for the wording, `InvoiceEvent`
 * as a dated log on the document, and a per-tenant scheduler. What was missing was the rule — who is chased,
 * when, and how often.
 *
 * The three that matter:
 *
 *  - **`test_nothing_is_sent_while_dunning_is_off`** — this is the only thing in the application that emails
 *    somebody outside the company, so the default has to be silence.
 *  - **`test_an_invoice_is_not_chased_again_inside_the_repeat_window`** — the repeat interval reads the event
 *    log rather than a column, which is what made this phase cheap; if it read nothing, a company would send
 *    a customer one reminder a day.
 *  - **`test_a_paid_invoice_is_never_chased`** — including the part-paid case, which changes the balance
 *    without changing the status.
 */
class OverdueRemindersTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const TODAY = '2026-09-30';

    private OverdueReminderService $reminders;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'dunning@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        $this->reminders = app(OverdueReminderService::class);
        $this->customer = Contact::create([
            'name' => 'Slow payer',
            'kind' => 'customer',
            'email' => 'accounts@slowpayer.test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Off by default, which is the whole safety of shipping this. */
    public function test_nothing_is_sent_while_dunning_is_off(): void
    {
        $this->overdueInvoice(45);

        $this->assertFalse($this->reminders->enabled());
        $this->assertCount(0, $this->reminders->send(self::TODAY));

        Notification::assertNothingSent();
    }

    /** Switched on, an invoice past the threshold is chased, at the address correspondence goes to. */
    public function test_an_overdue_invoice_is_chased(): void
    {
        $this->enableDunning();
        $invoice = $this->overdueInvoice(45);

        $sent = $this->reminders->send(self::TODAY);

        $this->assertCount(1, $sent);
        Notification::assertSentOnDemand(
            InvoiceOverdue::class,
            function (InvoiceOverdue $notification, array $channels, object $notifiable) use ($invoice): bool {
                return $notification->invoice->is($invoice)
                    && $notification->daysOverdue === 45
                    && $notifiable->routes['mail'] === 'accounts@slowpayer.test';
            },
        );
    }

    /** And the invoice's own history says so, which is where somebody arguing about it will look. */
    public function test_the_reminder_is_recorded_on_the_invoice(): void
    {
        $this->enableDunning();
        $invoice = $this->overdueInvoice(45);

        $this->reminders->send(self::TODAY);

        $event = $invoice->events()->where('event', InvoiceEvent::REMINDED)->firstOrFail();

        $this->assertStringContainsString('accounts@slowpayer.test', $event->description);
        $this->assertStringContainsString('45 days past due', $event->description);
    }

    /** Inside the threshold, nothing happens: an invoice a day late is not a debt collection matter. */
    public function test_an_invoice_inside_the_threshold_is_left_alone(): void
    {
        $this->enableDunning();
        $this->overdueInvoice(3);

        $this->assertCount(0, $this->reminders->due(self::TODAY));
    }

    /**
     * A second run inside the repeat window sends nothing.
     *
     * The scheduler runs daily, so without this a customer gets a reminder every morning until they pay —
     * which is how a company's mail ends up in a spam folder and its invoices unread.
     */
    public function test_an_invoice_is_not_chased_again_inside_the_repeat_window(): void
    {
        $this->enableDunning();
        $this->overdueInvoice(45);

        $this->assertCount(1, $this->reminders->send(self::TODAY));
        $this->assertCount(0, $this->reminders->send(self::TODAY));

        // Five days later is still inside the fortnight.
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->assertCount(0, $this->reminders->send('2026-10-05'));

        // Past it, and the same invoice is chased again.
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->assertCount(1, $this->reminders->send('2026-10-15'));
    }

    /** A paid invoice is never chased — nor a part-paid one whose balance has gone. */
    public function test_a_paid_invoice_is_never_chased(): void
    {
        $this->enableDunning();
        $invoice = $this->overdueInvoice(45);

        app(InvoiceService::class)->recordPayment($invoice, (float) $invoice->total, self::TODAY);

        $this->assertCount(0, $this->reminders->due(self::TODAY));
    }

    /** A part payment leaves something to chase, and the reminder is about what is left. */
    public function test_a_part_paid_invoice_is_chased_for_the_balance(): void
    {
        $this->enableDunning();
        $invoice = $this->overdueInvoice(45);

        app(InvoiceService::class)->recordPayment($invoice, 40_000, self::TODAY);

        $this->reminders->send(self::TODAY);

        $event = $invoice->refresh()->events()->where('event', InvoiceEvent::REMINDED)->firstOrFail();

        $this->assertSame('60000.00', (string) $event->amount);
    }

    /** A customer with no address is skipped rather than failing the whole run. */
    public function test_a_customer_with_no_email_is_skipped(): void
    {
        $this->enableDunning();
        $this->customer->update(['email' => null]);
        $this->overdueInvoice(45);

        $this->assertCount(0, $this->reminders->due(self::TODAY));
    }

    /** A draft is not a debt: it has been posted nowhere and the customer has never seen it. */
    public function test_a_draft_is_never_chased(): void
    {
        $this->enableDunning();

        $invoice = $this->invoice(45);

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertCount(0, $this->reminders->due(self::TODAY));
    }

    /** Nor is a bill this company owes: chasing a supplier for money we owe them is the wrong direction. */
    public function test_a_purchase_bill_is_never_chased(): void
    {
        $this->enableDunning();

        $bill = $this->invoice(45, Invoice::KIND_PURCHASE);
        $bill->lines()->update(['account_id' => \App\Modules\Accounting\Models\Account::where('code', '5700')->value('id')]);
        app(InvoiceService::class)->issue($bill);

        $this->assertCount(0, $this->reminders->due(self::TODAY));
    }

    /**
     * Each repeat is the next letter, read off the invoice's own history — `docs/bexio-gap-plan.md`
     * Phase 1, item 3. Capped at the final one: there is no fourth letter that is not a solicitor's.
     */
    public function test_each_repeat_moves_to_the_next_reminder_level(): void
    {
        $this->enableDunning();
        $invoice = $this->overdueInvoice(45);

        foreach (['2026-09-30', '2026-10-15', '2026-10-30', '2026-11-14'] as $day) {
            Carbon::setTestNow($day.' 10:00:00');
            $this->assertCount(1, $this->reminders->send($day), "on {$day}");
        }

        // After four, the next would still be the final letter.
        $this->assertSame(3, $this->reminders->levelFor($invoice));

        // What was actually sent each time: 1, 2, 3 — and 3 again, not 4.
        $sent = [];
        Notification::assertSentOnDemand(
            InvoiceOverdue::class,
            function (InvoiceOverdue $notification) use (&$sent): bool {
                $sent[] = $notification->level;

                return true;
            },
        );
        $this->assertSame([1, 2, 3, 3], $sent);

        $this->assertStringContainsString(
            'Overdue reminder 3 emailed to',
            $invoice->events()->where('event', InvoiceEvent::REMINDED)->latest('id')->firstOrFail()->description,
        );
    }

    /** The shipped letters harden by level; a company's own second letter replaces the shipped one. */
    public function test_the_letter_hardens_by_level_and_a_company_can_replace_each_one(): void
    {
        $invoice = $this->overdueInvoice(45);

        $this->assertStringStartsWith('Reminder:', (new InvoiceOverdue($invoice, 45, 1))->toMail($invoice->contact)->subject);
        $this->assertStringStartsWith('Second reminder:', (new InvoiceOverdue($invoice, 45, 2))->toMail($invoice->contact)->subject);
        $this->assertStringStartsWith('Final reminder:', (new InvoiceOverdue($invoice, 45, 3))->toMail($invoice->contact)->subject);

        EmailTemplate::create([
            'key' => 'invoice_overdue_2',
            'subject' => 'Reminder {reminder_level}: {invoice_number}',
            'is_active' => true,
        ]);

        $this->assertSame(
            'Reminder 2: '.$invoice->invoice_number,
            (new InvoiceOverdue($invoice, 45, 2))->toMail($invoice->contact)->subject,
        );
        // The third letter has no template of its own, so it is the shipped final one — not the second.
        $this->assertStringStartsWith('Final reminder:', (new InvoiceOverdue($invoice, 45, 3))->toMail($invoice->contact)->subject);
    }

    /** A company's own wording replaces the shipped text, which is the point of the template. */
    public function test_a_company_can_write_its_own_letter(): void
    {
        $this->assertArrayHasKey('invoice_overdue', EmailTemplate::PLACEHOLDERS);
        $this->assertContains('days_overdue', EmailTemplate::PLACEHOLDERS['invoice_overdue']);

        EmailTemplate::create([
            'key' => 'invoice_overdue',
            'subject' => 'Payment due: {invoice_number}',
            'body' => 'Dear {contact_name}, {amount} is {days_overdue} days late.',
            'is_active' => true,
        ]);

        $invoice = $this->overdueInvoice(45);

        $mail = (new InvoiceOverdue($invoice, 45))->toMail($invoice->contact);

        $this->assertSame('Payment due: '.$invoice->invoice_number, $mail->subject);
        $this->assertStringContainsString('45 days late', implode(' ', $mail->introLines));
    }

    // ───────────────────────────────────────────────────── fixtures ──

    private function enableDunning(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('invoicing.dunning_enabled', true);
        $settings->set('invoicing.dunning_after_days', 7);
        $settings->set('invoicing.dunning_repeat_days', 14);
    }

    private function invoice(int $daysOverdue, string $kind = Invoice::KIND_SALE): Invoice
    {
        $due = Carbon::parse(self::TODAY)->subDays($daysOverdue);

        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => $due->copy()->subDays(30)->toDateString(),
            'due_date' => $due->toDateString(),
        ]);

        $invoice->lines()->create([
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => 100_000,
            'line_total' => 100_000,
        ]);

        $invoice->forceFill(['subtotal' => 100_000, 'tax_amount' => 0, 'total' => 100_000])->save();

        return $invoice->refresh();
    }

    private function overdueInvoice(int $daysOverdue): Invoice
    {
        $invoice = $this->invoice($daysOverdue);
        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }
}
