<?php

namespace App\Support\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A document that can be the payee of a `Payment` and settles itself when the payment is approved —
 * `docs/bexio-gap-plan.md` Phase 3, item 11.
 *
 * Payments were paid to an Employee or a Beneficiary, and a supplier bill reached the bank file by being
 * retyped as one. A bill is neither: the party the file pays is the supplier *on* the bill, and the
 * posting the payment makes is the bill's own settlement — the bill already expensed what it bought and
 * credited Accounts Payable, so approving the payment relieves that and nothing else, through the
 * invoice's own path with its FX treatment, status change and history.
 *
 * A contract rather than a class name because Accounting may not name Invoicing (ModuleBoundaryTest):
 * Accounting asks the payable, and the module that owns the document answers.
 */
interface PayableDocument
{
    /** Who the bank file pays — a party with a name, a bank and account details. */
    public function payee(): ?Model;

    /** How payment screens name the document: "Bill BILL-2026-0007". */
    public function paymentLabel(): string;

    /**
     * Post what this payment settles and return the journal entry, or null when nothing posts.
     *
     * Called by PaymentService at approval in place of its own debit-and-credit, which would book the
     * document's cost a second time.
     */
    public function settleByPayment(Model $payment): ?Model;
}
