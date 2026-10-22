<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Notifications\InvoiceOverdue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Chasing overdue invoices — `docs/erpnext-gap-plan.md` Phase 5, and the cheapest item on it.
 *
 * Almost everything this needed already existed: `EmailTemplate` lets a company write its own wording,
 * `InvoiceEvent` is a dated log already shown on the document, `Contact::correspondenceEmail()` knows where
 * to write, and `schedule:run` runs a command once per tenant. What was missing was the *rule* — who gets
 * chased, when, and how often — which is this class and three settings.
 *
 * **It queries rather than reusing `aging()`, which is the one reuse that did not fit.** That method buckets
 * a whole book and returns totals; a reminder needs one row per invoice with the days on it, and asking for
 * the buckets to get at the rows would compute a report to send an email.
 *
 * **Off until a company turns it on.** `invoicing.dunning_enabled` defaults to false, so no customer of any
 * existing company receives anything because this shipped. That is the gap plan's constraint, and it matters
 * more here than in any other phase: the failure mode of getting this wrong is an email to somebody else's
 * customer.
 *
 * **The repeat interval reads the event log rather than a column.** A reminder is recorded as an
 * `InvoiceEvent`, so "when did we last chase this?" is a question the document already answers. No
 * migration, and the answer is visible to a person on the invoice's own history — which is where somebody
 * arguing with a customer about it will look.
 *
 * **No interest and no fee.** ERPNext's Dunning books both through the payment's deductions. That is a
 * posting decision — what rate, from when, to which account, and what happens to a customer who pays the
 * invoice but not the interest — and §4 of the plan says to take the reminder and leave the charge until
 * somebody actually charges one. Nothing here posts anything.
 */
class OverdueReminderService
{
    /** Days past due before the first reminder. */
    public const DEFAULT_AFTER_DAYS = 7;

    /** Days before the same invoice is chased again. */
    public const DEFAULT_REPEAT_DAYS = 14;

    /**
     * How far the letter hardens — `docs/bexio-gap-plan.md` Phase 1, item 3.
     *
     * The level is the number of reminders already on the invoice's history plus one, capped here: a fourth
     * and fifth reminder are the final one again, because there is no fourth letter to write that is not a
     * solicitor's. No column — the same event log that already answers "when did we last chase this?"
     * answers "how many times?".
     */
    public const MAX_LEVEL = 3;

    public function enabled(): bool
    {
        return (bool) setting('invoicing.dunning_enabled', false);
    }

    public function afterDays(): int
    {
        return max(0, (int) setting('invoicing.dunning_after_days', self::DEFAULT_AFTER_DAYS));
    }

    public function repeatDays(): int
    {
        // Floored at one: a repeat interval of zero would chase the same customer on every run of the
        // scheduler, which is once a day today and could be once an hour tomorrow.
        return max(1, (int) setting('invoicing.dunning_repeat_days', self::DEFAULT_REPEAT_DAYS));
    }

    /**
     * Which invoices are due a reminder today, and how overdue each is.
     *
     * Sale invoices only, and never a credit note — `ledgerSign()` makes a credit note's outstanding
     * negative, and there is nobody to chase for one. Only invoices with something actually left to pay,
     * because a part payment reduces the balance without changing the status.
     *
     * @return Collection<int, array{invoice: Invoice, days: int, level: int}>
     */
    public function due(?string $asOf = null): Collection
    {
        $today = Carbon::parse($asOf ?? now()->toDateString())->startOfDay();
        $after = $this->afterDays();

        return Invoice::query()
            ->where('kind', Invoice::KIND_SALE)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->subDays($after)->toDateString())
            // `contact` only: `correspondenceEmail()` queries the primary person directly rather than reading
            // a loaded relation, so eager-loading `people` would look like it was doing something.
            ->with('contact')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'invoice' => $invoice,
                'days' => (int) $invoice->due_date->startOfDay()->diffInDays($today, false),
            ])
            ->filter(fn (array $row): bool => $row['invoice']->outstanding() > 0.004)
            ->filter(fn (array $row): bool => $this->isQuietEnough($row['invoice'], $today))
            ->filter(fn (array $row): bool => filled($row['invoice']->contact?->correspondenceEmail()))
            ->map(fn (array $row): array => $row + ['level' => $this->levelFor($row['invoice'])])
            ->sortByDesc('days')
            ->values();
    }

    /**
     * Send them, and record what was sent.
     *
     * The event is written after the notification is queued, so an invoice whose send throws is chased again
     * on the next run rather than being marked as done. The other way round loses a reminder silently, which
     * is the failure a company would only discover from a customer who says they were never told.
     *
     * @return Collection<int, Invoice> what was chased
     */
    public function send(?string $asOf = null): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        return $this->due($asOf)->map(function (array $row): Invoice {
            $invoice = $row['invoice'];
            $email = $invoice->contact->correspondenceEmail();

            Notification::route('mail', $email)->notify(new InvoiceOverdue($invoice, $row['days'], $row['level']));

            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::REMINDED,
                "Overdue reminder {$row['level']} emailed to {$email} — {$row['days']} days past due",
                $invoice->outstanding(),
            );

            return $invoice;
        });
    }

    /** Which letter this invoice gets next: one more than it has had, up to the last one. */
    public function levelFor(Invoice $invoice): int
    {
        return min(self::MAX_LEVEL, $invoice->events()->where('event', InvoiceEvent::REMINDED)->count() + 1);
    }

    /**
     * Has enough time passed since this invoice was last chased?
     *
     * True for one that never has been, which is the case that matters on the first run: a company switching
     * this on has a backlog, and every overdue invoice in it is due its first reminder.
     */
    private function isQuietEnough(Invoice $invoice, Carbon $today): bool
    {
        $last = $invoice->events()
            ->where('event', InvoiceEvent::REMINDED)
            ->max('created_at');

        return $last === null
            || Carbon::parse($last)->startOfDay()->addDays($this->repeatDays())->lessThanOrEqualTo($today);
    }
}
