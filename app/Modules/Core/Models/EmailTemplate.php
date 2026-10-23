<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company's wording for one notification.
 *
 * Placeholders are written {like_this} and filled from whatever the notification
 * knows: a template naming something the notification does not provide is left as it
 * is rather than blanked, because a visible {employee_name} in a test email is a
 * better failure than a sentence with a hole in it reaching an employee.
 */
class EmailTemplate extends Model
{
    use Auditable;

    /** The notifications that can be overridden, and what each can fill in. */
    public const PLACEHOLDERS = [
        'payslip_issued' => ['employee_name', 'period', 'net_salary', 'company'],
        'payslip_rejected' => ['employee_name', 'period', 'reason', 'company'],
        'expense_claim_submitted' => ['employee_name', 'amount', 'description', 'claimed_on', 'company'],
        'expense_claim_decided' => ['employee_name', 'amount', 'description', 'decision', 'reason', 'company'],
        'employee_change_request' => ['employee_name', 'requester', 'company'],
        // A scheduled report, with the file attached — reports-expansion-plan.md Phase 8, item 7. No
        // recipient name among the placeholders on purpose: a recipient may be an address with no account
        // here, so there is no name to fill in and a greeting that said "Hello {employee_name}" would arrive
        // with the braces showing.
        'report_delivered' => ['report', 'period', 'company'],
        /*
         * An overdue reminder to a customer — `docs/erpnext-gap-plan.md` Phase 5.
         *
         * The one notification on this list that goes to somebody outside the company, which is why the
         * wording being a company's own matters more here than anywhere else: the shipped text is polite and
         * neutral, and a company that chases differently — or in Urdu — should not be sending ours.
         *
         * `contact_name` rather than `employee_name`: the reader is a customer. There is no amount for the
         * *account*, only for the invoice, because a reminder about one invoice is a reminder somebody can
         * act on and a statement of account is a different document (§4, item 1).
         */
        'invoice_overdue' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        /*
         * The second and third reminders — docs/bexio-gap-plan.md Phase 1, item 3. The level is how many
         * times the invoice has already been chased, read off its own history; a company that writes
         * neither of these sends `invoice_overdue` every time, as it always did, and one that writes only
         * the third gets the shipped second letter in between.
         */
        'invoice_overdue_2' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        'invoice_overdue_3' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'days_overdue', 'reminder_level', 'company'],
        // The monthly statement of account, PDF attached — docs/erpnext-gap-plan.md §4 item 1.
        'customer_statement' => ['contact_name', 'period', 'balance', 'company'],
        /*
         * The documents themselves, PDF attached — docs/bexio-gap-plan.md Phase 1, item 2. One key for
         * invoices and credit notes both: the placeholders are the same, and a company rewording one will
         * want the other to match. `due_date` is empty on a credit note.
         */
        'invoice_issued' => ['contact_name', 'invoice_number', 'amount', 'due_date', 'company'],
        'quotation_issued' => ['contact_name', 'quotation_number', 'total', 'valid_until', 'company'],
        // The morning digest of next actions, to a member — docs/bexio-gap-plan.md Phase 2, item 6. The
        // list of actions is the notification's own and is not a placeholder: a company rewords the
        // framing, not the facts.
        'next_action_due' => ['employee_name', 'count', 'company'],
        // Access to a company for somebody outside it — Phase 2, item 10.
        'company_invitation' => ['name', 'company', 'roles', 'inviter'],
        // A certificate emailed to the person it is about, attached — Phase 5, item 18.
        'document_issued' => ['employee_name', 'document', 'company'],
        // A contact's contract or document about to lapse, to whoever manages contacts — §3.7. Once per
        // threshold crossed, never once per day.
        'contact_document_expiring' => ['employee_name', 'contact_name', 'document', 'expires_on', 'company'],
    ];

    protected $fillable = ['key', 'subject', 'greeting', 'body', 'action_label', 'closing', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The company's override for this notification, if it has written one. */
    public static function for(string $key): ?self
    {
        return static::active()->where('key', $key)->first();
    }

    /**
     * One field with its placeholders filled.
     *
     * Null when the template does not set this field, which the caller reads as "use
     * your own wording" — a company that only wants to change the subject line should
     * not have to retype the body.
     *
     * @param  array<string, string|null>  $values
     */
    public function render(string $field, array $values): ?string
    {
        $text = trim((string) ($this->{$field} ?? ''));

        if ($text === '') {
            return null;
        }

        foreach ($values as $placeholder => $value) {
            if ($value === null) {
                continue;
            }

            $text = str_replace('{'.$placeholder.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * The body as paragraphs.
     *
     * @param  array<string, string|null>  $values
     * @return array<int, string>
     */
    public function paragraphs(array $values): array
    {
        $body = $this->render('body', $values);

        if ($body === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R+/', $body))));
    }
}
