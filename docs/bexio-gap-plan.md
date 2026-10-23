# What bexio has that we do not

**Created:** 2026-10-22
**Status:** All five phases built and **deployed to production on 2026-10-22** — phases 1–4 as
`13a736de`, phase 5 as `c23cb041`, both live on https://mizan.erbium.tech with every tenant migration
run and the baseline rows seeded (2310 Expense Reimbursements Payable, the `supplier-bill` transaction
type). What each item turned out to be is under §3.1 to §3.5. §3.6 (a claim paid by bank transfer)
was built afterwards and deployed on 2026-10-23 as `f0daf517`, with the `expense-reimbursement`
transaction type seeded into every tenant. §3.7 (contracts and documents on the contact) was built
and deployed on 2026-10-23. What stays unbuilt is listed under "Not planned, and
why" at the end of §3.
**Source read:** the bexio feature pages at `bexio.com/en-CH` — `functions`, `contact-management`,
`sales-process-and-expense-management`, `expense-management`, `accounting`, `payroll-accounting`,
`banking`, `bexio-pay`. Marketing pages, not a manual: where bexio says "automatic" the mechanism is
inferred. Our side was read by surface — models, migrations, Filament resources, services, scheduled
commands, the reports hub — on commit `d6d00ae3`.

The three earlier comparisons (`gnucash-gap-analysis.md`, `akaunting-gap-plan.md`,
`erpnext-gap-plan.md`) all concluded the ledger core here is sound and the gaps are at the edges. bexio
is the first comparison against a product that sells the *edges*: it is a Swiss SME suite whose whole
pitch is that a document photographed on a phone becomes a posted, paid, reconciled entry without a
person retyping anything. So this document is mostly about intake and outflow — how a document gets
in, how a document gets out — and much less about what happens to it in between, where we are ahead.

## 0. Not comparable, stated once

Six bexio features follow from Switzerland, not from any deficiency here. Listed so they are not asked
about again:

- **Swissdec / ELM** wage transmission to AHV, FAK and the pension fund. The Pakistani equivalents are
  files uploaded by hand (`FbrTaxFile` §149, `WithholdingStatement` §165); nothing in FBR or EOBI
  accepts a machine submission for payroll. Our `EobiReturn` is the right shape and is dead code — §3.
- **QR-bill.** The Swiss payment slip. Our invoice PDF already carries the FBR QR; a *payment* QR
  (Raast P2M) needs a merchant ID that no customer has asked for yet.
- **60+ bank interfaces** and automatic payment-order transmission. No Pakistani bank exposes a
  corporate statement or payment API; the iPayments CSV *is* the integration. The gap that is real is
  which documents reach that file — §3 Phase 3.
- **bexio Pay** — issued debit cards whose transactions post themselves. No card issuer to integrate.
- **Treuhänder directory** — a marketplace of fiduciaries. Not a software feature.
- **GeBüV audit-proof archive** — a Swiss legal retention requirement with no Pakistani counterpart.
  The cheap part of it (an attachment cannot be swapped after the document posts) comes free with
  §3 item 9.

Everything else bexio lists is a fair question, and §1 asks it.

## 1. Feature by feature

Verdicts: **Ahead** (we have more), **Covered**, **Partial** (the table exists, the screen or the
link does not), **Missing**.

### Contacts

| bexio | Here | Verdict |
|---|---|---|
| Categories, industries, languages, free groups | `contacts` has none of these columns; only a generic `CustomField` can emulate a category, and it cannot drive a filter or a document | **Missing** |
| Long-term discount per contact, applied automatically | `discount_pct` only on `quotation_lines`, typed per line; `invoice_lines` has no discount column | **Missing** |
| Internal contact person per customer | `owner_employee_id` exists on `leads` and `opportunities`, not on `contacts` | **Missing** |
| Excel import and export | `ContactCsvImporter` imports; nothing exports — no contact dataset in the reports explorer, no xlsx library anywhere | **Partial** |
| Contact data auto-fills documents | Due date (`Contact::dueDateFor()`) and `default_tax_rate_id` fill the invoice; address and email render on the PDF | **Covered** |
| Per-contact projects, quotes, invoices, credit notes, open balance | `ContactResource` shows People and Invoices (credit notes appear there by `kind`); `quotations.contact_id` and `projects.contact_id` exist and are not surfaced; balance only via the Statement PDF action | **Partial** |
| Conversation notes and call log | `activities` is polymorphic over Contact already (`create_crm_activity_tables`); the only screen that writes one is the Log action on an Opportunity | **Partial** |
| Reminders so follow-ups are not overlooked | `next_actions` with due date, assignee, snooze, and a resource; no scheduled command, no notification — `crms-plan.md` §9 specifies the `next_action.due` template key and it was never built | **Partial** |
| Contracts and documents stored on the contact | No `FileUpload` in Invoicing or Crm; `comments` is text only; file-backed documents exist for employees (`EmployeeDocument`) and construction | **Missing** |
| bexio Go mobile app | No manifest, no service worker; the API has no contacts endpoint (`desktop-mobile-plan.md` §0) | **Missing (planned)** |

### Sales documents

| bexio | Here | Verdict |
|---|---|---|
| Quote → order → invoice → credit note, one click each | `QuotationService::convertToInvoice()`, `InvoiceService::creditNote()`; **no sales-order stage** | **Partial** |
| Order confirmations, delivery notes | Neither; `erpnext-gap-plan.md` declined delivery notes ("invoices already move stock") | **Missing, by decision** |
| Credit notes | `KIND_CREDIT_NOTE`, own CN- series, mirrored posting, `CreditNotesIssued` report | **Covered** |
| Recurring invoices and subscriptions | `recurring_invoices` with a `(recurring_invoice_id, period)` unique guard, `RaiseRecurringInvoices` daily | **Covered** |
| Payment deadlines and reminder levels; reminders sent by mail | `contacts.payment_terms_days`; `OverdueReminderService` + `SendOverdueReminders`, off by default. **One level**: `dunning_after_days` and a flat `dunning_repeat_days`; no escalation, no fee | **Partial** |
| Document templates: fonts, logos, colours, multilingual | Logo upload only (`CompanyLetterhead::logoDataUri()`); colour and point size hardcoded in `letterhead-styles.blade.php`; one template; `lang/` has no application locale at all | **Partial** |
| Send documents by e-mail or post with your own email templates | **Invoices and quotes are download-only.** `InvoicePdfController` says so: "no portal and no tracked email", and records `PRINTED`. Quotations have no PDF at all. `EmailTemplate` exists and carries `invoice_overdue` and `customer_statement` — reminders and statements are the only documents a customer ever receives from the app | **Missing** |
| Products and services with prices | `products` has SKU, unit, valuation method, three GL accounts and **no price**; selecting a product on a line fills nothing | **Missing** |
| Hourly rates per customer, employee, activity or project | `projects.hourly_rate` → employee rate → company default via `TimesheetService::rateFor()`; `BillableHours` feeds `MonthlyBillingService` into a draft invoice | **Covered** (no per-customer or per-activity rate) |
| AI creates quotes and invoices from stored products | `CommandInterpreter` + `app/Support/Ai/Claude.php` exist with two resolvers: register transactions and expense claims. No invoice, quote or contact resolver | **Partial** |
| Sales analysis, Excel export | `RevenueByDimension`, `AgedReceivables`, `LargestDebtorsList`, invoice datasets in the explorer; CSV and PDF, no xlsx | **Covered** |
| VAT on lines, posted automatically | `tax_rates`, `invoice_lines.tax_rate_id`, `tax_inclusive`, input and output tax posted per rate; withholding at receipt with `TaxWithheldByCustomers` | **Ahead** — §153 withholding has no bexio equivalent |

### Expenses and purchases

| bexio | Here | Verdict |
|---|---|---|
| Supplier invoices and credit notes | `kind='purchase'` (BILL- series) → A/P; supplier corrections are debit notes | **Covered** |
| Scan receipts on the move; QR invoice captured with the right details | `expense_claims.receipt_path` is the only receipt field. **A purchase bill and a journal entry cannot carry an attachment at all.** No mobile capture | **Missing** |
| AI reads the receipt and learns your postings | Nothing reads an image or a PDF; the only AI is the text command bar | **Missing** |
| Collect, post and pay supplier invoices in a few clicks | Bills post on issue, correctly. **A bill never becomes a `Payment`** — `payments.payable` morphs to Employee and Beneficiary only, so `InvoiceService::recordPayment()` writes a journal entry and the bill never reaches `BankPaymentFile`. Bills are paid by hand outside the app and then recorded | **Partial — the circuit is open** |
| Choose the posting account | `invoice_lines.account_id` required on non-product lines. Expense claims have only a `transaction_type_id` and **do not post**; `ExpenseReports` names it: "an accrued liability in no account" | **Partial** |
| Filter by supplier, due date, posting account | One combined invoice list with a Project filter; no Bills screen, no supplier filter, no supplier statement | **Partial** |
| Employee expenses (bexio Go) | Expenses module: claim → approve/refuse with reason → settle via payslip or payment | **Ahead** |
| Recurring expenses | `recurring_invoices.kind='purchase'`, `scheduled_transactions`, `beneficiary_subscriptions` | **Ahead** |

### Accounting

| bexio | Here | Verdict |
|---|---|---|
| Standard charts of accounts to tailor | Three seeders chosen by company profile (`CompanyProfiles`), not a picker | **Covered** |
| VAT statement, several VAT methods | Income-tax only: §149, §153, §165, return packs. **No sales-tax return** (Annex-C); `fbr-advisor-questions.md` holds the sales-tax questions it depends on | **Missing — awaiting the advisor** |
| Balance sheet and P&L with previous-year comparison | `ComparativeStatement` for both, previous year/quarter/month | **Covered** |
| Manual journal entries | draft → pending → approved → posted with a second-approver rule | **Ahead** |
| Multi-currency with realised FX at payment | `currencies`, dated `exchange_rates`, foreign amounts on ledger lines, realised FX to 4450, unrealised via `CurrencyRevaluationService` | **Covered** (payments stay base-currency, deliberately) |
| Year-end closing | `FiscalYearClosingService` with blockers; posting into a closed year refused | **Covered** |
| Give your fiduciary free access | `Accountant` role, segregated by design, multi-company membership works. **No invite flow** — a platform admin creates the user by hand | **Partial** |
| Audit-proof digital archive | Plain tenant disk; immutability only on the activity log | **Missing — see §0** |
| Cost centres | Dimensions derived from the source document (`LedgerDimensions`), `ProfitAndLossByDimension`; a manual journal is always "Unassigned" | **Covered, differently** |
| Open items | `AgedReceivables`, `AgedPayables` | **Covered** |

### Banking

| bexio | Here | Verdict |
|---|---|---|
| Automatic reconciliation with leading banks | `BankReconciliationService::autoMatch()` matches statement lines to **unreconciled ledger lines** (exact amount ± 3 days, then reference). It never looks at an open invoice and cannot create the receipt | **Partial** |
| Incoming payments matched to open invoices; marked paid or overdue | Receipt is keyed separately (`RecordReceipt`), then the ledger line matches the statement. Two steps where bexio has none | **Partial** |
| Statement import | **A textarea you paste CSV into**, fixed column order (`BankStatementsTable.php:100`). No file upload, no column mapping, no per-bank format | **Partial** |
| Payment orders from bills, credit notes and payroll go to e-banking | Payroll and beneficiaries reach `SalaryBankFile` / `BankPaymentFile` (iPayments CSV). Bills do not — see Expenses | **Partial** |
| Multiple bank accounts | `company_bank_accounts`, several per company; no currency column | **Covered** |

### Payroll

| bexio | Here | Verdict |
|---|---|---|
| Wage types: fixed, per hour, 13th salary, expenses, child allowances | `pay_components` are data (earning/deduction) and expense reimbursement flows through the payslip. **No hourly pay type** (hourly only derives overtime), **no 13th-salary or annual-bonus mechanism** beyond a manual `bonus` | **Partial** |
| Social contributions calculated automatically | `StatutoryContributions` computes EOBI, PESSI/SESSI, PF with per-province ceilings — and by its own three rules **never writes**; HR confirms and creates the components | **Covered, by decision** — the rule is right; keep it |
| Store insurance details (accident, daily sickness) | One deduction column, `esi_health_insurance`. No policy, insurer, cover or expiry anywhere | **Missing** |
| Timeline of past changes, future adjustments | `employee_settings` effective-dated, auto-closing the prior row; `employee_job_history`; changes can route through `employee_change_requests` | **Ahead** |
| Entries / changes / exits reports for insurers | `HeadcountMovement` page, settlement reports; `EobiReturn.php` exists and **nothing references it** | **Partial** |
| Payslips e-mailed monthly | `PayslipDeliveryService`: email **and WhatsApp**, bulk, `sent_at`, employee accept/reject with a comment thread | **Ahead** |
| Annual wage statements, sent in bulk | `WithholdingCertificate`, `IncomeCertificate`, `ExperienceLetter` — **one employee at a time**, from the employee page | **Partial** |
| Payroll connected to accounting and e-banking | `PayrollPostingService`, `PayrollAutoPosting`, `SalaryBankExportService` | **Covered** |

### Platform

| bexio | Here | Verdict |
|---|---|---|
| Dashboard | 25 module-gated widgets, per-user saved layout | **Ahead** |
| Marketplace, 120+ apps | 15 API endpoints over 5 modules; no webhooks, no OAuth, no versioning. Connectors that do exist: WhatsApp (Twilio and Meta), Slack, SMTP, FBR, GnuCash import | **Missing** — and out of scope until somebody integrates |
| Time tracking | `timesheet_entries` with billable flag, approval, lock, three reports; no start/stop timer | **Covered** |
| Warehouse | Products, FIFO/LIFO/average lots, locations, movements posting to the ledger | **Ahead** |
| Project management | Projects, stints, environments with uptime monitoring and a public status page; margin and plan-vs-actual reports; no tasks or milestones | **Covered, differently** |

## 2. What this comparison does not change

Nothing in §1 touches the ledger, posting, closing, tax rates, multi-currency, fixed assets or payroll
calculation. All of that is Covered or Ahead, which is the same conclusion the three earlier
comparisons reached, and the plan below leaves it alone.

## 3. The plan

Ordered by value per day. Each item names what it reuses, because the constraint from
`erpnext-gap-plan.md` still holds: additive, built from machinery already here.

### Phase 1 — Documents leave the building · ~1 week

The single largest gap. A company on bexio emails a quote and an invoice from the screen it wrote them
on; here a person downloads a PDF and opens their mail client, and the app does not know it happened.

1. **Quotation PDF.** There is none. `resources/views/pdfs/invoice.blade.php` is the shape;
   `CompanyLetterhead` and the per-engine partials under `resources/views/pdfs/partials/dompdf-*.blade.php` carry over unchanged.
   *Done when: a quote renders with the same letterhead as an invoice.* **1–2 days.**
2. **Send from the app.** Two `EmailTemplate` keys, `quotation_issued` and `invoice_issued`, with the
   same placeholder discipline as `invoice_overdue`; a Send action on both resources that attaches the
   PDF and goes by email and WhatsApp — `PayslipDeliveryService` is the pattern, including `sent_at`.
   Add `InvoiceEvent::SENT` so the document history answers "when did we send this" — the first
   question on a late payment, and the one `akaunting-gap-plan.md` §3 item 7 asked for. WhatsApp
   delivery is a thing bexio sells as "by post"; we are ahead the moment this lands.
   *Done when: Send records the event, the customer receives the PDF, and resending is idempotent.*
   **2 days.**
3. **Reminder levels.** `invoices.dunning_level`, incremented by `SendOverdueReminders` on each
   repeat, selecting `invoice_overdue_1/2/3` wording (falling back to `invoice_overdue`). No fee, no
   interest — `DunningSettingsSection` says why. **1 day.**

#### 3.1 What Phase 1 turned out to be (built and deployed 2026-10-22)

Four things differ from the sketch above, and two of them are the plan being wrong.

- **Email only, no WhatsApp.** The payslip WhatsApp path goes through a provider template approved
  for payslips (`WHATSAPP_PAYSLIP_TEMPLATE`, `TWILIO_WHATSAPP_CONTENT_SID`) and a media link whose
  path and controller are payslip-shaped. An invoice through it would arrive captioned as a payslip,
  and a customer-document template exists at neither provider. `InvoiceDeliveryService` says so;
  WhatsApp for invoices and quotes is a template approval plus a second media route, when wanted.
- **No `dunning_level` column.** The level is the count of `reminded` events on the invoice plus one,
  capped at three — `OverdueReminderService::levelFor()`. The same event log that already decided
  the repeat interval decides the letter, so there was no migration, and the dry run prints the
  level it would send. Shipped second and final letters harden; `invoice_overdue_2` and
  `invoice_overdue_3` replace them per company, each falling back to `invoice_overdue`.
- **The invoice PDF route had nothing linking to it.** Both lists now carry a PDF button alongside
  Email / Send (`invoice.pdf`, new `quotation.pdf`).
- **A quote's Send does both halves and reports them separately.** `QuotationService::send()` is
  unchanged (fixes the figures; every status-ladder test calls it); `deliver()` emails the PDF to the
  contact's correspondence address or the lead's. No address: the quote is still sent, the screen
  says it was not emailed. There is no "email again" for a quote — revise and send the new version,
  or download the PDF. Invoices resend freely, each send its own `sent` event.

Tests: `CustomerDocumentDeliveryTest` (15) and the two level tests added to `OverdueRemindersTest`.

### Phase 2 — The contact becomes an account · ~1 week

Every table this phase needs already exists; it is relation managers and four columns.

4. **Contact 360.** Add `QuotationsRelationManager`, `ActivitiesRelationManager` and
   `NextActionsRelationManager` to `ContactResource::getRelations()`; the Log action from
   `OpportunityResource` moves to a shared action. Open balance as a header stat from the same query
   the Statement action runs. **1 day.**
5. **Contact columns.** `category` (select, company-defined list in settings), `owner_employee_id`,
   `default_discount_pct`; `invoice_lines.discount_pct` to match `quotation_lines`, applied on line
   create from the contact. *Risk: a discount column changes the line total; existing lines default to
   0 and are untouched.* **2 days.**
6. **Follow-up reminders.** A daily command that notifies the assignee of `next_actions` due today or
   overdue, through the `database` and `mail` channels every other notification here uses; the
   `next_action.due` template key `crms-plan.md` §9 already specifies. **1 day.**
7. **Contact export.** Register a `ContactDataset` in `Invoicing/module.php` beside the invoice
   datasets; the reports explorer's CSV export then covers contacts for free. **½ day.**
8. **Product price.** `products.list_price`; selecting a product on an invoice or quotation line fills
   `unit_price` (still editable). Pricing per customer stays per line. **½ day.**
9. **Attachments on bills and journals.** `invoices.attachment_path` and
   `journal_entries.attachment_path`, reusing `PettyCashBook::receiptUpload()`; locked once the
   document posts. This is the "no more lost receipts" half of bexio's expense module, and it is a
   `FileUpload`. **1 day.**
10. **Invite the accountant.** An Invite action on company members: email, role (`Accountant` by
    default), a signed link that creates the user and attaches them to this company only. The role and
    the multi-company membership exist; the front door does not. **1 day.**

#### 3.2 What Phase 2 turned out to be (built and deployed 2026-10-22)

The boundary did the designing. Invoicing may name neither CRM, Quotations nor Employees
(`ModuleBoundaryTest`), and the plan above had all three tabs and the owner relation on the Contact
model. So:

- **The tabs are contributed, not declared.** CRM's provider resolves `Contact::timeline()` and
  `Contact::nextActions()` and registers two relation managers on `ContactResource`; Quotations does
  the same for `quotations`. `ContactResource` reads `ResourceContributions`, exactly as the Employee
  screen gets its Projects tab. The Log action's four fields moved to `ActivityLogFields`, and
  `NextActionResource` now exposes its fields, due column and Done / Snooze actions for the tab to
  reuse.
- **No `invoice_lines.discount_pct`, and no `owner()` relation.** The account manager is a plain
  column labelled through `EmployeeOptions::labelFor()`, the shape `construction_workers.employee_id`
  already uses. The standing discount is applied where a catalogue price lands on a line
  (`LinePricing::applyProduct()` for invoices; the quote repeater sets `discount_pct` from it) and
  the invoice line carries the result — the rule a converted quote already followed. Nothing
  downstream of a line changed, which is what the plan's "risk" line was worried about.
- **Category is a datalist, not a settings list.** Free text, offered back from what has been typed;
  a filter on the list and on the dataset.
- **Follow-up reminders are one digest per person, once per due date.** `next_actions.reminded_on`,
  `crm:remind-next-actions` at 07:30, `NextActionDue` on every internal channel; a snooze is a new
  due date and is reminded again when it ends. `crms-plan.md` §9's rule, with its key spelled
  `next_action_due` like every other template key.
- **Attachments are changeable after posting.** The supplier's PDF often arrives after the bill was
  keyed from it, and a swap is in the audit log either way; locking would only keep evidence off the
  document it proves.
- **Invite makes no employee record.** `CompanyInvitations` creates the account with an unusable
  password and sends the panel's own reset link; an existing account is attached and told, and
  nothing else happens to it. `Accountant` is the default role.
- **Product price fills, and so does the line total.** Quantity and unit price are live, so the
  total the issue-time check insists on is typed by nobody.

Tests: `ContactAccountTest` (9), `NextActionRemindersTest` (6), `UserInvitationTest` (4).

### Phase 3 — Close the supplier circuit · ~1.5 weeks

11. **Bill → Payment → bank file.** Let `Invoice` (purchase kind) be a `payments.payable` type;
    `recordPayment()` on a bill raises or links a `Payment` so the bill reaches `BankPaymentFile` the
    way a beneficiary rent payment does today. A Bills tab on the invoice list with supplier, due-date
    and account filters. *Risk: `Payment::resolvedPaymentType()` reads bank details off the payee —
    bills need the contact's bank details, which `contacts` does not carry yet.* **3–4 days.**
12. **Statement import as a file.** Upload replaces the textarea; a per-bank column mapping saved on
    `company_bank_accounts` (JSON) so the second import of a Meezan statement needs no mapping at
    all. Extend `autoMatch()` to open sales invoices by amount and reference and offer "record the
    receipt from this line" — the step bexio collapses. **3–4 days.**
13. **Expense claims post.** On approval, debit the category's expense account and credit an accrued
    reimbursements liability; settlement reverses it. `ExpenseReports` stops having to explain the
    hole it reports. **2 days.**

#### 3.3 What Phase 3 turned out to be (built and deployed 2026-10-22)

The same boundary shaped all three items: Accounting may not name Invoicing or Expenses, and every one
of them needed Accounting to do something with the other module's document.

- **A bill is a `Payment`'s payee through a contract, not a morph type Accounting knows.**
  `App\Support\Contracts\PayableDocument` has three methods — who the file pays, how the payment
  screen names the document, and *settle yourself* — and `Invoice` implements it. `Payment::payee()`
  resolves the supplier for the bank file; `PaymentService::postEntryFor()` hands the posting to the
  document, so approving or releasing the payment runs `InvoiceService::recordPayment()` and the
  bill's expense is never booked twice. `contacts` gained `iban` and `account_no`, the two columns a
  beneficiary already had, so `BankFileAccount::resolve()` reads a supplier unchanged — including
  holding back an own-bank supplier with only an IBAN, which the test found before the bank did.
  Drafts count against what may be raised again. Tax withheld at source stays a Beneficiary's: a
  contact carries no section.
- **The Bills tab is a Filament list tab; the three filters work on sales too.** Supplier or
  customer, due-date range, and the posting account on any line.
- **No `autoMatch()` change.** "Record receipt" is a row action on the statement line, contributed
  by Invoicing through `ResourceContributions::addRecordAction()` — the registry grew a second kind
  of contribution rather than Accounting learning what an invoice is. The suggestion (the one open
  invoice owed exactly the line's amount, or the one whose number is in the narration) lives beside
  it in `StatementReceipts`, and the receipt posts into the statement's own account and matches the
  cash line in one transaction. One line settles one invoice in full; a transfer over several invoices
  is still the Record a receipt screen.
- **The mapping is by header name, saved on the bank account chosen at import.** `StatementCsv`
  reads a signed column or a credit/debit pair, brackets and `DR` suffixes, and a date format when
  Carbon cannot guess one. The textarea import is gone from the row; the bulk action keeps it.
- **The accrual's credit side is whatever payroll will debit.** `ExpenseClaimPosting` posts the
  claim's own category against `PayrollAccounts::id('expense_reimbursement')`, and the shipped
  mapping now points that at a new **2310 Expense Reimbursements Payable**. The payslip's
  reimbursement line debits the same account, so the two legs net wherever a company has pointed
  it — a company that kept 5600 has the expense in its category and 5600 washing to nil. Approval
  and posting are one transaction; a claim without a category books to Miscellaneous; deleting an
  approved claim reverses the accrual. Claims approved before this were never accrued and still reach
  the ledger through the payslip alone, which the report's note says. Paying a claim by bank
  transfer rather than payslip was left out here — the payee would be the employee, and nothing on
  an Employee payment could say it was settling a claim — and §3.6 then built it on this same
  contract.

Tests: `BillBankPaymentTest` (7), `StatementImportTest` (8), `ExpenseClaimPostingTest` (5); two
assertions in `ExpenseClaimsReportTest` that pinned the old "in no account" wording now pin the new.
New rows reach existing tenants through the baseline seeders on deploy (2310, the `supplier-bill`
type); `BillPaymentService` creates the type itself if it is missing.

### Phase 4 — Receipt intake with AI · ~1 week, behind `AI_COMMANDS_ENABLED`

14. **Receipt → draft bill.** `app/Support/Ai/Claude.php` already does structured outputs; give it the
    image or PDF from item 9 and ask for supplier, date, total, tax, NTN and a suggested account from
    this company's chart. The result is a **draft** a person confirms — the same "a person in between"
    rule `StatutoryContributions` and `CommandInterpreter` already enforce. Suppliers resolve against
    `contacts` by NTN or name. *Done when: a photographed restaurant bill becomes a draft BILL with the
    right supplier and total, and a wrong extraction is one field edit away.* **3–4 days.**
15. **Invoice and quote resolvers** for the existing command bar (`InvoiceCommandResolver`), so
    "quote Acme 40 hours at 5000" drafts a quotation. **2 days.**
16. **Mobile capture** is `desktop-mobile-plan.md` M1 (PWA, camera upload) and that plan's API
    hardening comes first. Not repeated here.

#### 3.4 What Phase 4 turned out to be (built and deployed 2026-10-22)

- **The model contract grew one method, not a second contract.** `StructuredModel::extractFromDocument()`
  takes the bytes and a media type beside the instruction and returns the same kind of thing `extract()`
  does — fields, never a tool. `Claude` sends the file as an image or a PDF document block before the
  text, under the same structured-output schema and the same cached system prompt; the local pattern
  parser says plainly that a word list cannot read a photograph and names the driver that can; the fake
  records what it was shown.
- **"New bill from receipt" is a header action on the invoice list**, visible once the command bot is on.
  The upload lands in the attachments directory and stays on the draft as its `attachment_path` — so a
  receipt that could not be read is still there to attach to a bill keyed by hand. `ReceiptReader`
  reads supplier, NTN, date, total, tax, a one-line description and an account chosen only from this
  company's expense accounts; the draft has one line, the tax typed rather than rated, and a tax the
  size of the bill is dropped rather than trusted. Suppliers resolve by NTN, then by name, and are
  created last, flagged in the memo.
- **The invoice and quote resolvers are one class and a subclass.** Same slots — a customer from a
  capped list, lines as *description, quantity, unit price* with figures left unparsed for
  `AmountWords`, a date — and a different document on confirmation. Both write drafts: issuing posts
  and reports to FBR, and Send on a quote's row fixes its figures and emails it, so neither is the
  bot's to do. "quote" is claimed before "invoice" is, and "bill" is deliberately nobody's claim: a bill
  here is a supplier's document. A quote takes the customer's standing discount per line.
- **The command bar's success toast reads whatever the result numbers itself by** — an entry, an
  invoice, a quote — where it used to assume a journal entry.
- Mobile capture (item 16) is still `desktop-mobile-plan.md` M1 and was not built here.

Tests: `ReceiptToBillTest` (8), `DocumentCommandResolversTest` (8). Both drive the fake model; the one
step that is not deterministic is the one that is stood in for.

### Phase 5 — Payroll edges · ~1 week

17. **Wire `EobiReturn`.** It is written and referenced by nothing. A report page in the hub beside
    `FbrTaxFile`. **½ day.**
18. **Bulk certificates.** A bulk action over employees producing `WithholdingCertificate` and
    `IncomeCertificate` PDFs for a fiscal year, sent through `PayslipDeliveryService` or downloaded as
    a zip. **1–2 days.**
19. **Employee insurance.** `employee_insurances` (kind, insurer, policy number, cover, premium,
    valid from/to, document); expiry surfaces in the existing `DocumentsExpiring` widget. **1–2 days.**
20. **Hourly and annual pay components.** A `basis` on `pay_components` — `monthly`, `per_hour`,
    `annual` — so an hourly wage multiplies attendance hours and a 13th salary or annual bonus accrues
    monthly and pays in a named month. No new columns on `payslips`. **2–3 days.**

#### 3.5 What Phase 5 turned out to be (built and deployed 2026-10-22)

- **The EOBI return is a page in the hub beside the FBR tax file**, with the same month picker, a
  CSV download, the service's own readiness sentence when a month has nothing, and the minimum-wage
  warnings under the table. The explorer pane draws it as a file report from a date. Nothing in the
  service changed; it had simply never been reached.
- **Bulk certificates are two bulk actions on the Employees list and one loop behind them.**
  `CertificateBatch` takes a "what is missing" closure and a "render" closure, bundles the issuable
  into a zip (`DocumentBundle`, which keeps two employees of the same name apart) or emails each
  person their own (`DocumentIssued`, to the account address or the personal one, as a payslip
  goes), and names everybody left out and why. The income certificate's action is Employees' own;
  the tax certificate's is Payroll's, contributed through a third kind of
  `ResourceContributions` entry, since Employees names nothing.
- **Insurance is a document kind, not a table.** A policy is a number, a term and a scan — what
  `employee_documents` already is — so it gained `insurance` as a kind and three columns a visa
  lacks (insurer, sum insured, premium), shown only for that kind. The expiry reminders, the report
  and the dashboard tile watch it unchanged. The plan's `employee_insurances` table would have been
  a second copy of the expiry machinery.
- **A component's amount has a basis.** `pay_components.basis` is `monthly` (every existing row),
  `per_hour` (times the hours attendance recorded in the month — nothing without Attendance, rather
  than a guess) or `annual` (whole in `pays_in_month`, nothing in the other eleven).
  `ComponentAmount` answers for both the calculation and the recorder, so the payslip and its
  component rows cannot disagree; the annual tax projection spreads a lump sum over twelve rather
  than multiplying it by the months ahead; attendance pro-rating leaves hourly and annual components
  alone. Not built: a monthly *accrual* of the annual amount in the ledger — the expense lands in the
  month it is paid, which is how the bonus column always behaved.

Tests: `EobiReturnPageTest` (4), `CertificateBatchTest` (5), `InsuranceDocumentTest` (2),
`PayComponentBasisTest` (3). The reports-hub floor in `ReportPaneTest` rises to 53 with the new
report.

#### 3.6 Paying a claim by bank transfer (built 2026-10-22, deployed 2026-10-23 as `f0daf517`)

The one thing §3.3 left out, built on the contract §3.3 introduced for bills. `ExpenseClaim`
implements `PayableDocument`: the employee is the payee, so the bank file pays them on the bank
details of their record, and `settleByPayment()` posts the claim's own settlement through
`ExpenseClaimBankPayment` — debit the reimbursement liability approval credited, credit the bank.
Accounting still names nothing in Expenses.

- **Pay through the bank** on an approved claim raises a draft `Payment` under a new
  `expense-reimbursement` transaction type (seeded; informational, like `supplier-bill`), from a chosen
  company bank account and value date. Approving the payment, or releasing it in a batch, settles
  the claim.
- **Raised, the claim leaves the payroll pool at once.** `reimbursableFor()` and
  `awaitingSettlementFor()` read a new `reimbursableByPayroll()` scope — approved and without a
  payment — so a draft waiting in the next file and a payslip cannot both pay it. The liability stands
  until the money moves: `awaitingSettlement()` still counts the claim, and the report's owed figure
  with it. A draft deleted before it went nulls `payment_id` (the foreign key already did) and the
  claim is payroll's again, with no code for that case.
- **A claim never accrued is accrued on the way.** The settlement calls `accrue()` first, a no-op for
  a claim that has its entry, so a claim approved before posting began books its expense once and
  then clears.
- Both lists say which way a claim went: the payments list reads "Expense claim #12 — EMP-007 -
  Name", and the claim's Reimbursed-on column shows "Bank transfer, payment #N" where a payslip
  would be, marked "not yet released" while it is a draft.

Not built: paying part of a claim (a claim is one amount, paid whole) and withholding on this path
(a reimbursement is not a payment for services).

Tests: `ExpenseClaimBankPaymentTest` (8).

#### 3.7 Contracts and documents on the contact (built and deployed 2026-10-23)

The Contacts table's last **Missing** row with no phase against it. `contact_documents` is
`employee_documents`' shape on the customer and the supplier — a kind, a title and reference number, a
term, the file, notes — and the **Documents** tab on the contact screen files, opens, replaces and
removes them. Invoicing owns it outright, so nothing is contributed from another module.

- **The expiry is watched the same way.** `invoicing:check-contact-documents` runs daily and warns
  whoever may edit contacts once per threshold crossed (60, 30, 7 days, in `config/invoicing.php`),
  never once per day; an expired document keeps being reported until somebody deals with it. The
  once-per-threshold rule is now one function, `App\Support\ExpiryThreshold`, and Lifecycle's
  `DocumentExpiryCheck` reads it too; the four expiry answers (`daysUntilExpiry()` and company) are one
  trait, `App\Models\Concerns\ExpiresOn`, shared with `EmployeeDocument`. Renewing — changing the
  expiry — resets the warning on the model itself, so no screen can forget.
- **Who is told.** Holders of `ContactUpdate`, as Lifecycle tells holders of `EmployeeDocumentUpdate`.
  The contact's owner would be the better reader, but the owner is an employee and Invoicing may not
  name Employees. The mail has its own `EmailTemplate` key, `contact_document_expiring`.
- **No permission group of its own.** Whoever may read the contact may read its documents; whoever may
  edit it may file them. A customer's contract is the credit limit's sensitivity, not a passport scan's.

Not built: a Documents Expiring report across contacts (the tab colours each row and the mail does the
rest), and documents on a lead before it becomes a contact.

Tests: `ContactDocumentsTest` (6).

### Not planned, and why

- **Sales orders, order confirmations, delivery notes** — the ERPNext comparison declined delivery
  notes and nobody has asked for an order stage. Add the day a customer wants a confirmation document.
- **Sales-tax return** — the sales-tax questions in `fbr-advisor-questions.md`. Built when the advisor answers.
- **Multilingual UI and Urdu documents** — Filament translates, Dompdf needs a Nastaliq font that
  works (the `dompdf-*` partials show how little it tolerates). Real work; wait for a customer who needs it.
- **Webhooks, OAuth, a marketplace** — nothing to integrate yet.
- **Corporate cards, bank APIs, Swissdec, QR-bill, fiduciary directory, WORM archive** — §0.
