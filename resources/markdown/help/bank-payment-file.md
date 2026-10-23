![Bank Payment File](/images/help/bank-payment-file.png)

## What this builds

The batch of outstanding payments — salaries and everything else (suppliers,
expense claims paid ahead of payday, petty cash replenishment, and more) — ready to hand to the bank as a CSV file.
Nothing here is paid until you actually download that file; everything on
this page is still "unreleased."

## Using it

Set a **Fiscal year** and **Month**, optionally narrow by **Transaction
type**, and set the **Value date** the bank should use. The table lists every
unreleased payment matching those filters.

Filtering by a salary month creates that month's salary payments
automatically if they don't exist yet — you don't need to generate them
anywhere else first. Payments with no payslip behind them (a supplier, a
petty cash replenishment) aren't tied to a month the same way and stay listed
until they're released, regardless of the month filter, so a payable entered
months ahead can show up early. The **Value date** on each row is what to
check before downloading.

## Rows that can't go out yet

A row can appear on the list without being releasable — the reason shows
against it:

- **Held back until accepted** — a salary payment whose employee hasn't
  acknowledged the payslip yet.
- **Rejected by the employee** — the employee rejected the payslip.
- **Wrong kind of account number on file** — the beneficiary's bank details
  aren't in a form this file can use.
- **Already released in an earlier batch**, or **No longer releasable** —
  historical rows, kept visible for context.

**Download CSV** releases only the rows that are actually releasable, as one
batch — held-back rows stay in the pool for a future batch. Confirmation
shows exactly how many payments will be released and how many are held back
before you commit.

## Undoing a released batch <!-- requires: PaymentUpdate -->

**Void a batch** exists for when a file was rejected by the bank or was built
by mistake. It returns every payment in the chosen batch back to the pool
(restored to whatever status — draft or approved — it held before release) so
it's available for the next batch. Nothing is deleted.

## Roles and permissions

Requires `ReportView` to see the page — worth noting even though this page
also releases and voids payments, which is more than a typical report does.
**Void a batch** additionally requires `PaymentUpdate`. Both are gated behind
the Accounting module being enabled for the company.
