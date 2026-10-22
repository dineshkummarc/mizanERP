<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Invoicing\Services\BillPaymentService;
use App\Modules\Projects\Models\Project;
use App\Support\Contracts\PayableDocument;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Throwable;

class Invoice extends Model implements PayableDocument
{
    use Auditable, HasCustomFields;

    // ─── PayableDocument — a bill paid through the bank file, docs/bexio-gap-plan.md Phase 3 ───

    /** The supplier on the bill, whose bank details go into the file. */
    public function payee(): ?EloquentModel
    {
        return $this->contact;
    }

    public function paymentLabel(): string
    {
        return ($this->kind === self::KIND_PURCHASE ? 'Bill ' : 'Invoice ').$this->invoice_number;
    }

    /** Approving the payment settles the bill through its own path; see BillPaymentService::settle(). */
    public function settleByPayment(EloquentModel $payment): ?EloquentModel
    {
        return app(BillPaymentService::class)->settle($this, $payment);
    }

    public const KIND_SALE = 'sale';

    public const KIND_PURCHASE = 'purchase';

    /**
     * A credit note: the mirror of a sale, and the only correction available once an
     * invoice has been reported to FBR and the 72-hour window has closed.
     *
     * Stored with **positive** amounts and posted as the reverse — credit A/R, debit
     * revenue, debit the tax accounts. Negative totals were the other option and they
     * break `postSystemEntry()`, which drops legs that are not greater than zero.
     * See the migration for the full argument.
     */
    public const KIND_CREDIT_NOTE = 'credit_note';

    /**
     * A debit note: the same document on the purchase side — `docs/erpnext-gap-plan.md` Phase 5.
     *
     * What it records is a claim against a supplier: goods returned, a bill overstated, a back-charge. It
     * debits Accounts Payable and credits back whatever the bill charged, so what the company owes falls by
     * exactly what the bill raised.
     *
     * **Stored with positive amounts and posted as the reverse**, exactly as a credit note is, and for the
     * same reason: `postSystemEntry()` drops legs that are not greater than zero, so a negative total is not
     * an option. Everything that reads `credits_invoice_id`, `creditedTotal()` and `creditableAmount()`
     * therefore works for both directions unchanged — see `creditNotes()`.
     *
     * **No FBR window and no Commissioner extension**, which is the one place it is *not* the mirror. Rule
     * 22 bounds the adjustment of tax on a supply this company *made* and reported; a supplier's bill is a
     * document this company received, and the input-tax adjustment rides on the credit note the supplier
     * issues. That reference belongs in the reason, where somebody reconciling can read it.
     */
    public const KIND_DEBIT_NOTE = 'debit_note';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * FBR reporting state — a second axis, deliberately not more values on
     * `status`. See the migration and docs/fbr-digital-invoicing-plan.md §2.
     *
     * `not_required` is the default and covers every invoice at a company that
     * is not integrated, which today is all of them.
     */
    public const FBR_NOT_REQUIRED = 'not_required';

    public const FBR_PENDING = 'pending';

    public const FBR_SUBMITTED = 'submitted';

    public const FBR_ACCEPTED = 'accepted';

    public const FBR_REJECTED = 'rejected';

    public const FBR_CANCELLED = 'cancelled';

    protected $fillable = [
        'recurring_invoice_id', 'period',
        'invoice_number', 'kind', 'credits_invoice_id', 'credit_reason',
        'commissioner_approval_ref', 'commissioner_approved_on',
        'currency_code', 'exchange_rate', 'contact_id', 'project_id', 'invoice_date', 'due_date',
        'status', 'subtotal', 'tax_amount', 'tax_inclusive', 'total', 'amount_paid', 'memo', 'attachment_path',
        'journal_entry_id', 'fiscal_year_id',
        'fbr_status', 'fbr_irn', 'fbr_usin', 'fbr_reported_at', 'fbr_qr_payload',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount_paid' => 0,
        'fbr_status' => self::FBR_NOT_REQUIRED,
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'period' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'fbr_reported_at' => 'datetime',
        'commissioner_approved_on' => 'date',
    ];

    protected static function booted()
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::nextInvoiceNumber($invoice->kind, $invoice->invoice_date);
            }
        });

        static::created(function (Invoice $invoice) {
            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::CREATED,
                match ($invoice->kind) {
                    self::KIND_PURCHASE => 'Bill',
                    self::KIND_CREDIT_NOTE => 'Credit note',
                    self::KIND_DEBIT_NOTE => 'Debit note',
                    default => 'Invoice',
                }.' raised as a draft',
            );
        });
    }

    public function recurringInvoice()
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function events()
    {
        return $this->hasMany(InvoiceEvent::class)->latest('id');
    }

    public static function nextInvoiceNumber(string $kind, $date = null): string
    {
        // CN- rather than INV-: a credit note is a different document, and a customer
        // holding one needs to be able to say so on the phone. It also keeps the two
        // series from interleaving, so "invoice 41 is missing" is never the answer.
        $prefix = match ($kind) {
            self::KIND_PURCHASE => 'BILL',
            self::KIND_CREDIT_NOTE => 'CN',
            // Its own series for the same reason CN has one: a supplier asked which document reduced their
            // bill needs an answer that is not "one of our invoices".
            self::KIND_DEBIT_NOTE => 'DN',
            default => 'INV',
        };
        $year = Carbon::parse($date ?? now())->format('Y');

        $last = static::where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('invoice_number')
            ->first();

        $lastNumber = $last ? (int) substr($last->invoice_number, -6) : 0;

        return sprintf('%s-%s-%06d', $prefix, $year, $lastNumber + 1);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The engagement this invoice belongs to — GnuCash's "job".
     *
     * A guarded soft dependency on Projects, the same shape as Billing's on
     * Advances: Invoicing must stay sellable to a company that does not run
     * projects, so every surface that offers this field checks
     * modules()->enabled('projects') first and the column is simply never
     * filled. The relation itself is harmless either way — the table exists in
     * every tenant, because licensing decides what is offered, not what is
     * migrated.
     */
    public function project()
    {
        // Imported properly rather than written as an inline FQCN. The first
        // version used the long form and ModuleBoundaryTest reported the
        // coupling as not existing — the lint reads `use` statements, so an
        // inline reference is a dependency the tool built to track them cannot
        // see. Hiding it would have been worse than declaring it.
        return $this->belongsTo(Project::class);
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function stockMovements()
    {
        return $this->hasManyThrough(
            StockMovement::class,
            InvoiceLine::class,
            'invoice_id',
            'source_id'
        )->where('stock_movements.source_type', ModuleMap::alias(InvoiceLine::class));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_PAID]);
    }

    public function fbrSubmissions()
    {
        return $this->hasMany(FbrSubmission::class)->latest('id');
    }

    /**
     * Does FBR currently consider this invoice a live sales-tax invoice?
     *
     * Only `accepted` counts. A rejected one never landed, a cancelled one has
     * been withdrawn, and `pending` / `submitted` are in flight — none of those
     * is something FBR is holding against the company, which is why only this
     * one constrains what may be done locally.
     */
    public function isFbrLive(): bool
    {
        return $this->fbr_status === self::FBR_ACCEPTED;
    }

    /**
     * When the 72-hour correction window closes, or null if it never opened.
     *
     * Counted from FBR's acceptance rather than from local issuance — see
     * config/fbr.php, where that assumption is flagged as still needing
     * confirmation.
     */
    public function fbrCorrectionWindowClosesAt(): ?Carbon
    {
        if (! $this->isFbrLive() || $this->fbr_reported_at === null) {
            return null;
        }

        return $this->fbr_reported_at->copy()->addHours(
            (int) setting('fbr.correction_window_hours', 72),
        );
    }

    /**
     * May this invoice still be cancelled or edited at FBR without going to the
     * Commissioner?
     *
     * False when it was never reported, which is deliberate: callers ask this to
     * decide whether a *remote* correction is available, and for an unreported
     * invoice there is nothing remote to correct. Whether a LOCAL void is
     * allowed is a different question, and InvoiceService owns it.
     */
    public function fbrCorrectionWindowOpen(): bool
    {
        $closesAt = $this->fbrCorrectionWindowClosesAt();

        return $closesAt !== null && $closesAt->isFuture();
    }

    /**
     * The FBR verification QR, as a data URI the PDF can embed — or null when
     * this invoice was never accepted.
     *
     * Rendered from `fbr_qr_payload`, which is STORED at acceptance and never
     * regenerated (plan §4): a reprint must carry the QR the invoice was
     * reported with. A server-generated PNG rather than any JS library,
     * because Dompdf runs no JS — chillerlan/php-qrcode is already installed
     * (it rides in with Filament) and a PNG data URI renders identically in
     * both PDF engines.
     */
    public function fbrQrDataUri(): ?string
    {
        if (blank($this->fbr_qr_payload)) {
            return null;
        }

        try {
            return (new QRCode(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG,
                'scale' => 3,
            ])))->render($this->fbr_qr_payload);
        } catch (Throwable) {
            // ponytail: no GD extension degrades to the IRN printed as text —
            // the PDF template already falls back. Wire Imagick output here if
            // a host ever ships PHP without GD.
            return null;
        }
    }

    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    public function isSale(): bool
    {
        return $this->kind === self::KIND_SALE;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === self::KIND_CREDIT_NOTE;
    }

    public function isDebitNote(): bool
    {
        return $this->kind === self::KIND_DEBIT_NOTE;
    }

    /**
     * Is this document a correction of another one? — Phase 5.
     *
     * The two notes behave identically in every place that does not care which direction the money goes:
     * their totals come from their own lines rather than from the rate table, they move no stock, and they
     * subtract from whatever adds documents up. Asking this rather than `isCreditNote()` twice is what kept
     * the debit note from being a second set of branches beside the first.
     */
    public function isAdjustment(): bool
    {
        return $this->isCreditNote() || $this->isDebitNote();
    }

    /** The invoice or bill this note reverses. Null on an ordinary invoice. */
    public function creditedInvoice()
    {
        return $this->belongsTo(self::class, 'credits_invoice_id');
    }

    /**
     * Notes raised against this document: credit notes on a sale, debit notes on a bill.
     *
     * One relation for both, because `credits_invoice_id` means "the document this one adjusts" and a
     * document can only be adjusted in the direction it was raised. That is what let the debit note reuse
     * every guard below — `creditedTotal()`, `creditableAmount()` and `isFullyCredited()` — instead of
     * growing a parallel set that could disagree with them.
     */
    public function creditNotes()
    {
        return $this->hasMany(self::class, 'credits_invoice_id');
    }

    /**
     * How much of this invoice has been credited, counting drafts.
     *
     * Drafts count, and that is the deliberate half. A draft credit note has not posted,
     * so it changes no balance — but it exists because somebody has decided to give this
     * money back, and letting a second person raise another one for the full amount while
     * the first sits unissued is how an invoice ends up credited twice. Voided ones do not
     * count: a void has been reversed out of the ledger and abandoned.
     */
    public function creditedTotal(): float
    {
        return round((float) $this->creditNotes()
            ->where('status', '!=', self::STATUS_VOID)
            ->sum('total'), 2);
    }

    /** What is still available to credit — the guard against crediting more than was billed. */
    public function creditableAmount(): float
    {
        return round((float) $this->total - $this->creditedTotal(), 2);
    }

    public function isFullyCredited(): bool
    {
        return $this->creditableAmount() <= 0.004 && $this->creditedTotal() > 0;
    }

    /**
     * The last day a credit note against this invoice can still adjust output tax.
     *
     * Rule 22 of the Sales Tax Rules 2006: 180 days from the supply. Counted from
     * `invoice_date` rather than `fbr_reported_at`, and that is the deliberate part — the rule
     * is about the *supply*, not about when it was reported, so an invoice reported late has
     * less time left, not more. Using the reporting date would quietly extend the window for
     * exactly the companies that were slow to report.
     */
    public function creditNoteDeadline(): Carbon
    {
        return Carbon::parse($this->invoice_date)
            ->addDays((int) setting('fbr.credit_note_days', 180))
            ->endOfDay();
    }

    /** The furthest that deadline can be pushed, once, with the Commissioner's extension. */
    public function creditNoteExtendedDeadline(): Carbon
    {
        return $this->creditNoteDeadline()
            ->copy()
            ->addDays((int) setting('fbr.credit_note_extension_days', 180));
    }

    public function creditNoteWindowOpen(?Carbon $on = null): bool
    {
        return ($on ?? now())->lessThanOrEqualTo($this->creditNoteDeadline());
    }

    /** Whether a rule 22 extension has been recorded against this document. */
    public function hasCommissionerApproval(): bool
    {
        return filled($this->commissioner_approval_ref);
    }

    /**
     * What this document does to the receivable, sign included.
     *
     * A credit note reduces what the customer owes, so anything that adds invoices up —
     * aged receivables, the dashboard's unpaid total — has to subtract it. Reading
     * `outstanding()` and forgetting the sign is the one way this feature makes the books
     * wrong rather than merely incomplete, so the sign lives on the model where every
     * caller gets it, rather than in each caller.
     */
    public function ledgerSign(): int
    {
        return $this->isAdjustment() ? -1 : 1;
    }

    public function signedOutstanding(): float
    {
        return round($this->outstanding() * $this->ledgerSign(), 2);
    }

    public function signedBaseOutstanding(): float
    {
        return round($this->baseOutstanding() * $this->ledgerSign(), 2);
    }

    /**
     * The currency this invoice is billed in. Null on the column means the base one,
     * which is what every invoice raised before currencies existed is.
     */
    public function currencyCode(): string
    {
        return $this->currency_code ?: Currency::baseCode();
    }

    public function isForeignCurrency(): bool
    {
        return $this->currencyCode() !== Currency::baseCode();
    }

    /**
     * The rate this invoice was posted at.
     *
     * Read from the column rather than looked up, because a rate recorded later for the
     * invoice date must not silently restate an invoice that has already been issued and
     * whose journal entry says something else.
     */
    public function rate(): float
    {
        return (float) ($this->exchange_rate ?: 1);
    }

    /**
     * The invoice in base currency: what the ledger holds for it.
     *
     * Reports that add invoices together have to use these. Summing `total` across
     * invoices in different currencies produces a number, which is precisely how this
     * goes wrong unnoticed.
     */
    public function baseTotal(): float
    {
        return round((float) $this->total * $this->rate(), 2);
    }

    public function basePaid(): float
    {
        return round((float) $this->amount_paid * $this->rate(), 2);
    }

    /**
     * What is still owed, in base, at the rate it was booked at.
     *
     * Deliberately not at today's rate: the receivable was booked at the invoice rate,
     * and the whole difference between that and the rate on the day the money arrives is
     * recognised then, as a realised gain or loss.
     */
    public function baseOutstanding(): float
    {
        return round($this->baseTotal() - $this->basePaid(), 2);
    }
}
