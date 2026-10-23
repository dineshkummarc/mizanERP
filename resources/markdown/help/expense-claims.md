![Expense Claims](/images/help/expense-claims.png)

## What an expense claim is

Something an employee paid for out of pocket and is owed back. It follows the
same submit → decide pattern as an Employee Change Request, deliberately —
one workflow, one place to get the approval rules right.

## Submitting one <!-- requires: ExpenseClaimCreate -->

Click **New**. **Employee** defaults to your own record (most claims are
somebody claiming for themselves, though anyone with access to a downline can
submit for a report). Fill in when it was **spent**, a plain-language
**description**, the **amount**, an optional **category** (the same
categories company payments use, so a claim and a company purchase for the
same thing land under one heading in reports), and optionally attach a
**receipt** (image or PDF).

Every new claim notifies everyone who can approve claims — except the person
submitting it, even if they also happen to hold that permission.

## Deciding a claim <!-- requires: ExpenseClaimApprove -->

Whoever holds the approve permission sees **Approve** and **Refuse** on any
pending claim that isn't their own — **a claim can never be decided by the
person who submitted it**, enforced regardless of role, including
Administrator. This isn't a permission gap to fix; it's the point of having an
approver.

- **Approve** commits to reimbursing the claimed amount with the employee's
  next payslip. It cannot be undone from here — see below.
- **Refuse** requires a reason, sent to the person who claimed it. Being told
  no without being told why is exactly what this step exists to prevent.

Once a claim leaves **Pending** (approved or refused), it can no longer be
edited or deleted by anyone — the decision is final on the record.

## Getting reimbursed

An **Approved** claim is picked up automatically the next time that
employee's payslip is generated: payroll adds up everything awaiting
settlement and reimburses what fits into the payslip's reimbursement figure,
oldest claims first. A claim moves to **Reimbursed** once a payslip actually
covers it — you'll see which payslip against the claim in the list. If a
payslip that reimbursed a claim is later corrected downward or deleted, the
claim is put back to Approved and unpaid rather than left in limbo.

### Paying it through the bank instead <!-- requires: ExpenseClaimApprove -->

An approved claim can also be paid on its own, ahead of payday. **Pay through
the bank** on the row raises a draft payment for the next bank payment file,
to the bank details on the employee's record, from the company account you
choose. From that moment payroll leaves the claim alone: a payslip and a bank
payment must never both pay it. The claim moves to **Reimbursed** when the
payment is approved or released in a batch, and the list shows the payment
number where a payslip would be. Delete the draft payment before it goes out
and the claim is back with payroll. Needs the Accounting module.

## Roles and permissions

- **View / Create / Update** — Employees hold these for their **own** claims
  only (Update only while still Pending — see below); Accountant, Manager,
  CEO and Administrator see their reporting downline as well as their own.
- **Approve** (`ExpenseClaimApprove`) — Accountant, Manager, CEO,
  Administrator. Employees never hold it, by design: an approver is always
  somebody else.
- **Update / Delete** only work while a claim is still **Pending** — once
  decided, it's locked.
- Depends on the Expenses module being enabled.
