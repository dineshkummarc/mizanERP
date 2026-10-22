<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reminder to a customer that an invoice is past due — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * **Mail only, and to somebody with no account here.** Every other notification in this directory reaches a
 * user; this one is routed to an address on a `Contact`, so there is no database channel to write to and no
 * broadcast to make. `via()` says so rather than relying on the notifiable to have no such channel.
 *
 * **The letter, and not the interest.** ERPNext's Dunning books an interest charge and a fee through the
 * payment's deductions, which is a posting decision — what rate, from what date, to which income account,
 * and whether a customer who pays the invoice but not the interest is then in arrears again. The gap plan's
 * §4 says to take the reminder and leave the interest until somebody actually charges it, and this class is
 * that line: nothing here posts anything.
 *
 * **One invoice per email, deliberately.** A single message listing everything a customer owes is a
 * statement of account, which needs a per-recipient rendered document and is named as its own item in §4.
 * A reminder about one overdue invoice is a thing somebody can act on without a reconciliation.
 *
 * **Three letters, hardening** — `docs/bexio-gap-plan.md` Phase 1, item 3. The level is read off the
 * invoice's history by the reminder service. A company writes `invoice_overdue_2` and `invoice_overdue_3`
 * for its own second and third letters; with neither, the shipped ones below are sent, and with only
 * `invoice_overdue`, that one is sent every time, as it was before levels existed.
 */
class InvoiceOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice, public int $daysOverdue, public int $level = 1) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = $this->invoice->contact?->name ?? 'there';
        $number = $this->invoice->invoice_number;
        $amount = number_format($this->invoice->outstanding(), 2);
        $due = $this->invoice->due_date?->format('j M Y') ?? '—';
        $days = $this->daysOverdue;
        $company = Company::current()?->name;

        // Neutral on purpose at level one. A first reminder that opens by threatening is a first reminder
        // that gets a customer's lawyer rather than a payment, and a company that wants a harder letter
        // writes one. No interest and no fee at any level — nothing here posts.
        [$subject, $lines] = match ($this->level) {
            1 => ["Reminder: invoice {$number} is overdue", [
                "Invoice {$number} was due on {$due} and {$amount} of it is still outstanding — {$days} days ago.",
                'If it has been paid in the last few days, thank you — this will have crossed with it, and '
                    .'you can ignore it.',
                'If something is holding it up, tell us what and we will sort it out.',
            ]],
            2 => ["Second reminder: invoice {$number} is {$days} days overdue", [
                "This is our second reminder about invoice {$number}: it was due on {$due}, {$days} days ago, "
                    ."and {$amount} is still outstanding.",
                'Please arrange payment, or tell us what is holding it up — if there is a problem with the '
                    .'invoice we would rather hear it than wait.',
            ]],
            default => ["Final reminder: invoice {$number} is {$days} days overdue", [
                "Invoice {$number} is now {$days} days overdue and {$amount} remains unpaid after two reminders.",
                'Please settle it within seven days. If we do not hear from you, we will have to put further '
                    .'work on hold until the account is brought up to date.',
            ]],
        };

        return TemplatedMail::apply(
            new MailMessage,
            $this->templateKey(),
            [
                'contact_name' => $contact,
                'invoice_number' => $number,
                'amount' => $amount,
                'due_date' => $due,
                'days_overdue' => (string) $days,
                'reminder_level' => (string) $this->level,
                'company' => $company,
            ],
            subject: $subject,
            greeting: "Hello {$contact},",
            lines: $lines,
        );
    }

    /** The company's letter for this level where it has written one; its first letter otherwise. */
    public function templateKey(): string
    {
        $key = "invoice_overdue_{$this->level}";

        return $this->level > 1 && EmailTemplate::for($key) ? $key : 'invoice_overdue';
    }
}
