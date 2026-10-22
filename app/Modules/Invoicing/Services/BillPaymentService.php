<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Support\ModuleMap;
use InvalidArgumentException;

/**
 * Paying a supplier bill through the bank file — `docs/bexio-gap-plan.md` Phase 3, item 11.
 *
 * The circuit this closes: a bill posted correctly, and then could never become a `Payment`, so it never
 * reached the one bank file the application writes. Bills were paid by hand outside the application and
 * recorded afterwards. Now a bill raises a draft payment for the next file, and **approving or releasing
 * that payment settles the bill** — through `InvoiceService::recordPayment()`, so the FX treatment, the
 * status change and the invoice's history are the tested ones.
 *
 * **Drafts count against what is left to pay.** A second "pay through the bank" on the same bill would
 * otherwise raise the same money twice; the first draft has not settled anything yet, but it is already
 * on its way out of the door.
 *
 * **No withholding on this path.** Tax withheld at source is assigned to a Beneficiary's section (ERPNext
 * plan Phase 4), and a contact carries no section; a supplier the company withholds from is still paid
 * as a beneficiary.
 */
class BillPaymentService
{
    /** The transaction type every bill payment is filed under, created on first use. */
    public const TRANSACTION_CODE = 'supplier-bill';

    public function __construct(private InvoiceService $invoices) {}

    /**
     * Raise a draft payment for a bill, for the next bank file.
     *
     * @param  float|null  $amount  what to pay; null for everything still left to pay
     * @param  int|null  $bankAccountId  which company account it leaves; null for the default
     */
    public function raise(Invoice $bill, ?float $amount = null, ?int $bankAccountId = null, ?string $valueDate = null): Payment
    {
        if ($bill->kind !== Invoice::KIND_PURCHASE) {
            throw new InvalidArgumentException(
                'Only a supplier bill is paid through the bank file. A customer invoice is settled by a receipt.'
            );
        }

        if (! $bill->isOpen()) {
            throw new InvalidArgumentException("{$bill->invoice_number} is {$bill->status}; only an issued bill can be paid.");
        }

        if (! $bill->contact) {
            throw new InvalidArgumentException("{$bill->invoice_number} names no supplier to pay.");
        }

        $left = $this->leftToPay($bill);
        $amount = round($amount ?? $left, 2);

        if ($left <= 0.004) {
            throw new InvalidArgumentException(
                "Nothing is left to pay on {$bill->invoice_number} — a payment for the rest is already waiting in the next bank file."
            );
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('The payment has to be for something.');
        }

        if ($amount > $left + 0.004) {
            throw new InvalidArgumentException(sprintf(
                '%s is more than the %s left to pay on %s after the payments already raised for it.',
                number_format($amount, 2),
                number_format($left, 2),
                $bill->invoice_number,
            ));
        }

        $payment = Payment::create([
            'payable_type' => ModuleMap::alias(Invoice::class),
            'payable_id' => $bill->getKey(),
            'transaction_type_id' => $this->transactionType()->getKey(),
            'company_bank_account_id' => $bankAccountId ?? CompanyBankAccount::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->value('id'),
            'amount' => $amount,
            'reference' => $bill->invoice_number,
            'details' => "{$bill->invoice_number} — {$bill->contact->name}",
            'value_date' => $valueDate ?? now()->toDateString(),
            'status' => Payment::STATUS_DRAFT,
        ]);

        InvoiceEvent::record(
            $bill,
            InvoiceEvent::PAYMENT_RAISED,
            'Bank payment #'.$payment->getKey().' of '.number_format($amount, 2).' raised for the next bank file',
            $amount,
        );

        return $payment;
    }

    /**
     * What a new payment may still be raised for: the balance, less drafts already waiting.
     *
     * Approved and exported payments have settled the bill already, so they are in `outstanding()`;
     * drafts have not, and are not.
     */
    public function leftToPay(Invoice $bill): float
    {
        $drafts = Payment::query()
            ->where('payable_type', ModuleMap::alias(Invoice::class))
            ->where('payable_id', $bill->getKey())
            ->where('status', Payment::STATUS_DRAFT)
            ->sum('amount');

        return round($bill->outstanding() - (float) $drafts, 2);
    }

    /**
     * The settlement a payment's approval makes — `PayableDocument::settleByPayment()`.
     *
     * The invoice's own path posts it (debit Accounts Payable, credit the bank) and records the event;
     * the entry it wrote is the newest one against the bill, read back for the payment to point at.
     */
    public function settle(Invoice $bill, Payment $payment): ?JournalEntry
    {
        $this->invoices->recordPayment(
            $bill,
            (float) $payment->amount,
            ($payment->value_date ?? now())->toDateString(),
            null,
            null,
            'bank payment #'.$payment->getKey(),
        );

        return JournalEntry::forSource(Invoice::class, $bill->getKey())->latest('id')->first();
    }

    private function transactionType(): TransactionType
    {
        return TransactionType::firstOrCreate(
            ['code' => self::TRANSACTION_CODE],
            [
                'name' => 'Supplier bill',
                'account_id' => Account::where('code', '2400')->value('id'),
                'description' => 'Settles a supplier bill through the bank file; the bill itself carries the expense',
            ],
        );
    }
}
