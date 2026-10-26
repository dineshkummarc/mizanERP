# Construction Management — Plan

**Status:** **The plan is complete. Phases 0 to 11, 2026-08-22.** §4.3's fifth mechanism — one of every source type
reconciling to `0.00` through the real services — is green, which is the assertion the whole of §3 and §4 was written
against. Whole suite: **3,446 tests, 11,714 assertions, nothing failing.**

Phase 9a built what §13 says to build before anything else in the phase: **the delay-event notice clock and its
notification** — 34 tests, and the first tables of a new module, `construction_field`. §13's argument for the ordering is
the one to keep in mind for the rest of the phase: *"A due-date with a notification attached is worth more commercially
than the entire programme: a valid claim lost to a missed notice is the single most common way a contractor donates
money, and it fails in absolute silence."* Every other failure in this suite leaves a wrong figure somewhere a report can
find. This one leaves nothing at all.

Phase 9b built the site diary and three of its five children — 28 tests — and the two rules that make a diary evidence
rather than a note: **one entry per job per day**, where §16.1 calls the constraint the feature, and **approval locks
the row**, because "an editable site diary is not evidence". The lock reaches the manpower, plant and events, which is
where the numbers a claim is built from live.

The join between the two sub-phases is the thing to carry forward: **a diary event that cost time and has no delay
event behind it is the exposure**, and it is now a query rather than somebody's memory. The diary is where such an
event is written down on the day; §13's clock is what is running against it. The events tab raises the delay event in
one action, dated the day the thing happened rather than the day somebody noticed.

Phase 9c finished the diary with its last two children and settled the question 9b left open. **The delivery is the
docket, not the valuation** — §16.1's list of what it carries has no rate and no amount in it, and that absence is what
keeps it out of the way of §5's priced goods receipt. **And `is_materials_on_site` corroborates Phase 8c's stock figure
rather than replacing it**, because a diary flag never comes off when material is built in: a total of flagged dockets
would overstate what is on site by everything already consumed, and the error would grow every month with nothing saying
so.

What the flag buys instead is two answers stock cannot give. Where there is no cost module there is no stock ledger, and
the dockets are the whole of the evidence — which the certificate now says in those words, fixing a Phase 8c defect
where the panel simply vanished. And where there *is* one, **a docket with no priced receipt behind it is material the
job received that the cost ledger has never heard about**: cost understated, margin overstated, and the same silence
§13's clock exists for, one document chain along.

Phase 9d built the RFI register, and the shape it shares with 9b and 9c is now the section's signature: **a register's
most valuable column is the one that is empty.** An RFI carrying a stated time impact with no delay event behind it is a
notice period already running with nothing chasing it — the third instance, after the diary's unnotified event and the
docket accounts never saw. Each is a fact the application already holds, joined to a consequence nobody was watching.

Phase 9e built the submittal register, and it is where that pattern acquires a limit worth stating. The three exposures
above are all **money somebody else owes**. A submittal late to *submit* is not: the contractor is the one who submits,
so its lateness is a risk to manage and there is deliberately no notice anywhere near it. What *is* claimable is a
reviewer who kept the drawing longer than the contract's review period — which is why the turnaround lives on the round,
beside the period it was measured against, and why the notice is raised per round rather than per item.

The register's own contribution is arithmetic rather than a missing link: **the submit-by date is computed backwards
from the date the item is needed on site**, and on a real job that produces a list of items already late before anybody
has done anything wrong, because nobody did the subtraction when the programme was agreed.

Phase 9f built punch lists, which is where §16 stops being a set of registers and starts paying for §11. **The
punch-list holdback is now a figure rather than a note.** §11's AIA release holds back the cost of rectifying open items
flagged as affecting practical completion — and until 9f existed `RetentionService` could only take that holdback as
zero and say in words that it did not know. It now gives one of three answers and each says which it is: unknown
without the field module, genuinely nil with nothing flagged, or a figure with the count of items behind it that carry
no cost estimate.

That closes the note 9a left unconditional, and the lesson underneath it is the one worth carrying into every remaining
phase: **a module licence is not a proxy for data existing.** The guard used to ask "is the field module here" when the
question it needed was "is there an open punch value to read", and 9a licensing the module three sub-phases before punch
items existed is exactly how those two come apart.

Phase 9g stored the programme, and §13's first line is the whole of it: **store the programme; never solve it.** No
forward pass, no backward pass, no float derivation, no critical-path solver. `is_critical` and `total_float_days` are
imported columns with a `source` printed beside them, and a test asserts by reflection that no method on the programme's
own classes is even *named* for scheduling — because a behavioural test would pass on the day somebody added
`recalculateDates()`.

What the programme buys is the fifth exposure in this family, and the largest: **days a priced contract milestone is
late against the accepted programme, less the extension of time actually awarded.** Liquidated damages accrue against
that remainder. It is computable only because §13 keeps baseline and planned dates apart and because §13's delay events
record what was *determined* rather than what was claimed — a test asserts that a claim for forty days excuses nothing
until somebody determines it.

Phase 8a built §6's foundation, and it is **the cross-plan migration this document calls "the highest-value cross-plan
note"**: `stock_locations` owned by Inventory, `stock_movements.stock_location_id` with its backfill, the movement-type
enum expanded once for both plans, a location-aware valuation API, and the site-store path on a goods receipt that
Phase 5c had to refuse — 16 tests in `StockLocationTest` plus 3 in the receipt file. `docs/retail-stores-pos-plan.md`
has been updated in the same breath: its Phase 2 is now smaller and its Phase 0 note says exactly what not to build
again, which is the follow-through §6 says two plans usually fail to do.

Phase 8c closed §6 with materials on site — 16 tests — and it is where the shape of §6 pays off: the figure is
`remaining_quantity` on the lots at a job's store, so **receipt puts material on site and issue takes it off, and
nothing re-derives received-minus-issued by hand.** It appears on the cost report as the part of `actual` that has not
been used yet, and on the payment certificate as **evidence beside the materials claim rather than as the claim** — what
is claimed is a contractual assessment at contract rates, and conflating the two would tell a certifier their
assessment had been made for them.

Phase 8b built the issue document — 28 tests — and **the rule that carries it is that posting an issue does not change
the job's total cost.** §6 makes materials on site "delivered, costed, not yet consumed", so the *receipt* is what
costs the material; an issue reclassifies it out of the code it arrived on and on to the code it was used on, as
`reclass` entries that sum to zero. Charging on issue as well would charge every stocked delivery twice, and both
figures would look like material cost on the same job with nothing to disagree with either.

Phase 7 built §7 in `construction_costing` in four parts, 129 tests: trades, the worker register that holds people who
are not employees, and the dated rate table with §7.2's five-tier ladder (7a, 33 tests); the site sheet, the snapshot at
approval, and burden as its own entry (7b, 37 tests); plant, internal hire recovery and §7.3's two-way match against
hire invoices (7c, 37 tests); and the timesheet import behind its guard (7d, 22 tests).

**§7's whole shape is one sentence: three things charge a job at a rate, and each of them owes a credit somewhere.**
Labour burden credits Labour Burden Absorbed, internal plant credits Plant Internal Hire Recovery, and both are
`pending` for §11 to post. §7.3's warning is the one to carry into Phase 11 — charge either and never absorb it and
"job cost exceeds GL cost by exactly the burden, growing every month, with no error anywhere".

**The one thing to carry forward from 7c is which machines book cost, because it is the difference between a job
costed once and a job costed twice.** §4.1 settles it: where a GL document already exists for a cost, construction
mirrors or stays out of the way. An owned excavator has no invoice, so its log *is* the cost — internal hire, credited
to recovery. A hired one has a supplier invoice that already reaches the job through §5's allocation chain, so its log
books **nothing** and becomes the check against that invoice instead. Both would look like plant cost on the same job
and the same code, which is why the register carries a *Books cost* column rather than leaving it to be inferred.

Two more things to carry forward before the write-ups.

**A plan contradiction, resolved.** §3.2's enum comment offered burden as an example of `memo` ("deliberately never
reaches the GL") while §7.3 requires burden charged to jobs to credit Labour Burden Absorbed. §7.3 wins and the
examples were the error — recorded in full at §7.3, and the comments in `CostEntry` and the costing migration have been
corrected, because a wrong example in the schema is what a later reader copies.

**A trap this suite has now fallen into three times.** A `date` cast serialises as `Y-m-d H:i:s`, so comparing such a
column to a `Y-m-d` string in SQL misses the boundary day — and silently differs between SQLite and MySQL. A rate
effective the first of April did not apply on the first of April. Phase 2 hit it twice and wrote the answer on
`CostPeriod::scopeStarting()`, whose docblock ends *"anything comparing a date to this column goes through here"*;
Phase 7a proved that a warning attached to one column does not travel to the next one. **Every new dated column needs
`whereDate()` from the first query written against it.**

Phase 6 built the payable side in three parts, 94 tests: compliance documents that block certification and an
override that records who and why (6a, 30 tests — Phase 6's stated exit condition, met ahead of the rest of the
phase); back-charges, where the useful rule is that notice is a state the register can be queried on rather than a
habit somebody has (6b, 38 tests); and the subcontract certificate relieving its commitment (6c, 26 tests), which
is the first place two construction siblings have had to talk to each other about money.

Two things about 6c are worth carrying forward before the phase write-ups:

- **The relief target is cumulative and the row written is the movement**, which is §8's certificate rule arriving
  one module along. Every case that an incremental design has to special-case then falls out of the arithmetic —
  most usefully, voiding an *earlier* certificate while a later one stands correctly moves nothing, which
  "reverse what that certificate relieved" gets backwards.
- **Which module reaches which was forced rather than chosen, and it decided where a screen goes.** Neither
  sibling declares the other, so the pair may only be coupled one way or it is a cycle — and the certificate is
  what triggers the relief, so `construction_contracts` is the side that reaches. That is why
  `commitments.contract_id` is set from the *contract's* screen rather than by a picker on the order form, which
  is the first place anybody would look for it.

Phase 5 built procurement end to end in `construction_costing`: requisitions, commitments, goods receipts, invoice
allocations with their queue screen, and the computed three-way match — 115 tests across five files. All four
sub-phases of 5 and all three of 6 are written up under their phases below.

Phase 4 built `construction_contracts` in full: the contract and its item schedule under both standards, variations
with the agreed-versus-forecast rule, progress claims, payment certificates with their deductions, the retention
ledger with its nightly reconciliation, the printed certificate under both PDF engines, and §10.4's invoice
hand-off — 137 tests across six files.

Two things about the last two sub-phases are worth carrying forward:

- **The printed document is engine-independent because the pagination is not in the engine.**
  `CertificateSchedule` chunks the continuation sheet in PHP, so the sheet breaks the same way whether headless
  Chrome or Dompdf renders it. The honest limit is stated in `ConstructionCertificatePrintTest`: the PDF *bytes*
  differ by engine because one writer is Chrome's and the other Dompdf's, so what is asserted identical is the
  **document** — the same pages, the same rows on each, the same brought- and carried-forward figures, the same
  "page n of m". The only difference between the two renderings is the Dompdf stylesheet in the head, which is
  what §10.5 promises.
- **The account map is a settings block contributed by this module**, which is what §18.2's refusal to add
  `'core' => [… 'construction']` requires and what the `SettingsSection` registry from the packaging plan exists
  for. Building it caught a real hole on the way: validating the codes against the chart locked *every* company
  out of Company Settings, because the construction accounts arrive with the construction profile rather than
  with the base chart. The block is now hidden without the module, and a code left at its shipped default is not
  validated there at all — the refusal it needs belongs at the point of use, where `ConstructionAccounts::id()`
  names the missing code and the seeder.

Three decisions inside Phase 4 are worth carrying forward:

- **The retention ledger is written only by `RetentionService`, and issuing a certificate is the trigger.** The
  certification service computes the deduction row and calls that service, which links the movement to the row —
  so §11's reconciliation compares two registers line by line rather than in total.
- **The reconciliation compares *held* movements with the certificates, not the balance.** Comparing the balance
  would report a difference on every job that has ever released retention, which is a report people stop reading.
- **`contract_sum_to_date` is not a column.** It is the original plus the agreed variations, both frozen on the
  certificate, so a third stored column could only ever disagree with its own two inputs. §8.4's G702 mapping
  already calls line 3 derived; the header list that also names it is the redundancy, not the mapping.

**Phases 0 to 3.** Phase 1 built the spine — jobs, the WBS, locations, the cost-code library and the ISO 19650
document register. Phase 2 built the cost ledger and its live
report. Phase 3 built budget versions with the baseline, progress measurements, earned value, forecast runs and
§3.5's four-column report, in `ConstructionEarnedValueTest` (38 tests). Three things about Phase 3 as built are
worth carrying forward, each found by a test rather than by reading:

- **The model is `JobBudget`, not `BudgetVersion`.** Accounting already owns the `App\Models\BudgetLine` morph
  alias, and aliases are keyed on the class basename — so the pair is `JobBudget` and `JobBudgetLine` over the
  planned `construction_budget_versions` and `construction_budget_lines` tables.
- **Budget and earned value roll up the job tree, not just cost.** A budget is held on the job that was
  tendered while reporting happens at whatever level somebody asks, so the report reads the *current* version of
  every job in the subtree and earned value reads every *baseline* in it. With only the root's own version, a
  development that is exactly on budget reports a variance equal to its entire spend.
- **One measurement per control account per period is enforced in the service, not by the index.** Most control
  accounts have a null `wbs_node_id`, and both MySQL and SQLite treat nulls in a unique index as distinct — so
  the index lets a second row through and the job's earned value doubles, which reads as ahead of schedule.
  Re-measuring an open month revises the figure in place; a locked one is refused.

Phase 0's own record follows. Both halves of Phase 0 are done: the one
prerequisite in another module — `InvoiceService::purchaseEntryLines()` now flips the leg for a negative line,
so a subcontract retention line can post, covered by `PurchaseInvoiceNegativeLineTest`, and it corrected the
risk entry that described the failure as silent — and all four decisions, recorded in the Phases section
below. In brief: `ConstructionQhse` casing; `stock_locations` owned by Inventory rather than `store_id`, and
written into `docs/retail-stores-pos-plan.md` as well; reference data ships as structure plus CSV import with
**no seeded code lists**, which makes the redistribution licensing question non-blocking instead of answering
it; and construction is a seventh navigation domain. Phase 0 was decisions and one fix; the tables arrived with
Phase 1. Its last outstanding item, the **query-budget test**, was parked until there was a report to budget and
is now `test_the_report_does_not_grow_a_query_per_row` — the four-column report and the earned-value metrics take
the same number of queries for four hundred cost codes as for two.
**Created:** 2026-08-14
**Covers:** the job and its classification (§1–§2), the job-cost ledger and its reconciliation to the
books (§3–§4), procurement, site stores, labour and plant (§5–§7), the head contract, variations,
certification and
retention under both FIDIC and AIA (§8–§11), subcontracts (§12), schedule and earned value (§13–§14),
document control to ISO 19650 (§15), field operations (§16), QHSE to ISO 9001 and ISO 45001 (§17), and
the module boundary (§18)

Goal: let a contractor run a building or civils job from this application end to end — win it, price
it, buy for it, staff it, build it, measure it, get certified and paid for it, and know at any moment
what it has cost against what it was sold for. The commercial half is built to the two contract
families the world actually uses (**FIDIC** for international and most of Asia, the Middle East and
Africa; **AIA** for North America), selected per job, over one set of tables. The site half is built to
the management-system standards a client audit asks for: **ISO 19650** for information, **ISO 9001**
for quality, **ISO 45001** for safety.

Six findings shape everything below, and the second and fourth are the whole difficulty.

1. **`projects` already exists and is the wrong shape.** `App\Modules\Projects\Models\Project` is
   delivery teams plus **uptime monitoring** — `project_environments` with health checks, certificate
   expiry and a public status page
   (`database/migrations/tenant/2026_07_27_140000_create_projects_tables.php`). It has no tasks, no
   milestones, no WBS, no budget and no cost. A construction job is not that entity with a different
   label, and this plan does not extend it — §1 says what it does instead, and what stops two
   project-shaped things in one panel from confusing everybody who uses it.
2. **The ledger has no dimension column.** `journal_entry_lines` is `journal_entry_id, account_id,
   debit_amount, credit_amount, description` and nothing else
   (`2026_07_15_082837_create_journal_entry_lines_table.php`). Per-job profit therefore needs either a
   job on the line, a chart of accounts per job, or a second ledger that reconciles.
   `docs/retail-stores-pos-plan.md` §2.2 hit the identical fork for stores and put the dimension on the
   line. **This plan takes the third branch by decision** (§3), which buys a cost model the GL cannot
   express and owes a reconciliation that cannot be skipped (§4).
3. **`stock_movements` has no location.** Product, type, quantity, unit cost, `remaining_quantity`, a
   date, and nothing else (`2026_07_16_120000_create_products_and_stock_movements_tables.php`);
   `InventoryValuationService::onHand()` sums every movement for a product, everywhere. "What steel is
   on site" is unanswerable today. The POS plan's finding #1 is the same column, and §6 says which of
   the two plans owns the migration, because two plans silently assuming the other will add it is how
   neither does.
4. **There is no procurement at all.** No purchase orders, no goods receipts, no requisitions, no
   three-way match, no warehouses — a supplier bill is `invoices.kind = 'purchase'` and a supplier is
   `contacts.kind ∈ {supplier, both}`. **Commitment is the number a construction manager actually runs
   the job by** — what is already promised to somebody but not yet invoiced — and there is nothing in
   this application that holds it. §5 is therefore the largest net-new schema surface here, larger than
   the contract half.
5. **Timesheets bill but never cost.** `timesheet_entries` carries `minutes`, `is_billable` and a rate
   ladder of project → employee → company default
   (`2026_08_13_120000_create_timesheet_entries_table.php`). There is a charge-out rate and no cost
   rate, no burden, no overtime premium as cost. Labour is usually the largest controllable cost on a
   job, and §7 has to add costing without breaking the billing that rate ladder already serves.
6. **Budgets are per GL account per fiscal period.** `budgets` + `budget_lines` are
   `(fiscal_year, account, period, amount)`. A construction budget is per cost code per job, is not
   bounded by a fiscal year, and moves every time a variation is approved. It is a new table, not an
   extension of that one.

## What already exists and is reusable

Written down because the size of this plan depends on it. The accounting spine is built and this plan
adds no second one.

- **Double-entry posting is solved and has exactly one door.**
  `App\Modules\Accounting\Services\JournalEntryService::create($header, $lines)` validates the balance,
  converts foreign currency through `Support\Money`, derives the fiscal year from the entry date and
  wraps the write in `TenantTransaction::run()`. Everything in §4 posts through it and nothing here
  writes a journal line directly.
- **A module→account mapping precedent exists.** `App\Modules\Accounting\Support\PayrollAccounts`
  resolves which account a payroll component posts to. §4's `ConstructionAccounts` is that class with a
  different vocabulary — WIP, cost of construction, retention receivable, retention payable, uncertified
  revenue — and copying its shape is cheaper than inventing a configuration screen.
- **Invoicing is complete in both directions.** `invoices.kind` is `sale|purchase`, so the client
  invoice and the supplier bill are the same table with the same posting service, with tax per line,
  credit notes, multi-currency and payment settlement against A/R and A/P. **No document in this plan
  re-implements an invoice** — a payment certificate *produces* one (§10).
- **Clients and subcontractors are already Contacts.** `contacts.kind` is `customer|supplier|both`,
  with NTN/CNIC, payment terms and a bank. A subcontractor is a supplier; this plan adds the
  construction-specific facts about one (prequalification, insurance, retention terms) alongside rather
  than a second party table.
- **Imprest floats are a solved pattern.** `PettyCashService` runs opening balance → vouchers →
  closing → replenishment. A site petty-cash float is the same shape with a job on the voucher.
- **Row-level scoping has a precedent.** `App\Support\EmployeeAccess` plus a `getEloquentQuery()`
  filter per resource is how manager/downline visibility works. Job-team scoping — a site engineer sees
  their sites and no others — is the same shape with a different resolver (§1).
- **PDFs are already fought for.** `spatie/laravel-pdf` with Dompdf and per-engine override partials,
  and the constraints are known and recorded. §10's G703 is the hardest page in this plan and §10 says
  how it survives Dompdf.
- **Comments, custom fields and the audit trail are polymorphic and free.** `HasComments`,
  `HasCustomFields` and `Auditable` attach to any model. An RFI needs a discussion thread, not a
  `rfi_comments` table.
- **Module mechanics are documented and enforced.** `docs/new-module-checklist.md` is the thirteen-step
  authority — registry entry, company profile, plugin, provider, `ModuleMap`, policies, permissions,
  tenant migrations, route middleware — and `ModuleCoverageTest`, `ModuleGatingTest`,
  `ModuleBoundaryTest` and `CompanyProfileTest` fail the build for anything missed. **This plan restates
  none of it** and §18 gives only what is specific to these modules.

## Not doing

- **Extending `Project`.** Decided, and finding #1 is why: that model is an environment monitor. Making
  a construction job a subtype of it would put `health_status` and `certificate_expiry` on a
  bridge deck, and would make the construction modules unsellable to a company that never bought
  Projects. The cost of the decision is two project-shaped nouns in one application, and §1 pays it
  with a naming and navigation rule rather than pretending it does not exist.
- **Dimensions on `journal_entry_lines`.** Also decided, and §3 defends it. The consequence — two
  places a cost can live — is the most expensive thing in this plan if it is left to look after itself,
  which is why §4 is a design section and not a paragraph.
- **A Gantt engine.** §13 builds activities with dates, progress and predecessors because SPI and a
  three-week look-ahead need them. Critical-path calculation, resource levelling, calendars with
  exceptions and a drag-and-drop bar chart are Primavera P6 and MS Project, both of which every serious
  contractor already owns. The plan imports from them and exports to them; it does not compete.
- **A BIM viewer or model server.** §15 is a **document register** to ISO 19650 — containers, naming,
  suitability, revisions, states, transmittals. Rendering IFC, clash detection and federated models are
  a different product with a different cost base. The register is what the standard actually audits.
- **An estimating package.** Cost codes carry budgets and rates (§2, §3); a first-principles estimating
  build-up with resource libraries, waste factors, productivity norms and price books is its own
  application. The plan takes an estimate **in** — as budget lines against cost codes, importable from a
  spreadsheet — and does not produce one.
- **Legal compliance as code.** §10 notes that statutory payment regimes exist — the UK Construction
  Act's payment notice and pay-less notice with their five-day and seven-day clocks, US prompt-payment
  and lien statutes, and the retention reforms now in front of the UK parliament — and models them as
  **configurable notice periods and document types**, not as hardcoded rules per jurisdiction. A
  hardcoded statute is wrong the day it is amended and nobody notices.
- **Payroll for construction trades.** Certified payroll, prevailing wage, union fringes and CIS/1099
  deduction schemes are per-jurisdiction payroll products. This plan **consumes** a labour cost rate
  (§7) and **records** whether a certified payroll report was received from a subcontractor as a
  compliance document (§12). It does not compute one.
- **A separate mobile application.** §16's daily log and punch list are Filament pages that work on a
  phone browser, with photo upload and offline tolerance treated as a later question. A native site app
  is a second application with its own release cycle, and `docs/retail-stores-pos-plan.md` §12 already
  records what offline-first costs when it is real. *The later question was answered 2026-09-27:
  offline capture for the field pages is phase O2 of `docs/desktop-mobile-plan.md` §5a — the shared
  sync engine owns it, not this plan.*
- **Equipment telematics and fuel cards.** §7 costs plant by hours at a rate. Reading hour meters off a
  telematics API is one integration per manufacturer and belongs to whoever buys it.

## §1 What a job is

A **job** is one contract to build one thing at one place: a tower, a road package, a fit-out, a
substation. It is the unit that gets a contract sum, a cost code structure, a site, a team, a
programme, certificates, retention and a final account. Everything else in this plan hangs off it.

`construction_jobs` (tenant):
`code` (unique, `J-2026-014`), `name`, `description`, `parent_id` (self FK — see below), `path`
(materialised ancestor path, `/12/47/`), `client_contact_id` (FK `contacts`, nullable until award),
`project_id` (nullable FK `projects`, **guarded** — see §18), `nature`
(`building|civils|infrastructure|fit_out|mep|marine|other`), `status`
(`tender|awarded|mobilising|in_progress|suspended|substantial_completion|defects_liability|final_account|closed|cancelled|lost`),
`contract_standard` (`fidic|aia|custom`), `currency_code`, `site_address_line_1/2`, `site_city`,
`site_country`, `latitude`, `longitude`, `commencement_date`, `planned_completion_date`,
`revised_completion_date` (moves only through an approved extension of time — §13),
`actual_completion_date`, `substantial_completion_date` (the Taking-Over date under FIDIC, the
Substantial Completion date under AIA — one column, two labels), `defects_period_days`,
`final_certificate_date`, `contract_sum` (original, `decimal(15,2)`), `retention_pct`,
`retention_cap_pct` (retention stops accruing at this share of the contract sum — the "limit of
retention"), `retention_first_release_pct`, `advance_payment_pct`, `advance_recovery_start_pct`,
`advance_recovery_rate_pct`, `liquidated_damages_per_day`, `liquidated_damages_cap_pct`,
`payment_terms_days`, `certifier_contact_id` (the Engineer under FIDIC, the Architect under AIA),
`manager_employee_id`, `qs_employee_id`, `site_agent_employee_id`, `closed_at`, timestamps.

`construction_job_employee` mirrors `project_employee` exactly — `job_id`, `employee_id`, `role`,
`from_date`, `to_date` (null = open stint), unique on `(job_id, employee_id, from_date)` — and is what
`JobAccess` reads (§18). A site engineer sees the sites they are on; a commercial manager sees all of
them by permission, not by assignment.

**The revised contract sum is computed, never stored.** Original sum plus approved variations (§9), read
through one accessor. The house rule from `docs/new-module-checklist.md` §10 applies with unusual force
here: a stored contract sum that drifts from the variation register is a number two people will quote in
a meeting and neither will be able to explain.

### 1.1 Two project-shaped nouns in one panel

This is the cost of the self-contained decision and it is worth naming plainly. A company that licenses
both Projects and Construction has a `Project` (a software engagement with environments and a status
page) and a `Job` (a building site), and they are unrelated tables with similar-sounding names.

Three rules keep them apart, and all three are cheap:

- **Different nouns, different domains.** The model is `Job` and never `Project`. Construction gets its
  own rail domain (§18); `ProjectResource` stays where it is, in the `Employee` group under **People**.
  A user never sees the two in one menu.
- **The label is per-tenant.** `Job` is the default, and a company setting renames it to `Site`,
  `Contract` or `Works` across every construction screen. A Gulf contractor says "project", a UK
  subcontractor says "site", a US GC says "job", and arguing about it is not worth a code change.
- **A job may *name* a project, never *be* one.** `construction_jobs.project_id` is nullable and
  offered only when `modules()->enabled('projects')`, exactly as `invoices.project_id` already works
  (`2026_08_07_120000_add_project_to_invoices_table.php`, and the coupling is already recorded in
  `ModuleBoundaryTest::KNOWN_COUPLINGS`). It buys a company that runs both the ability to see a job's
  hours in the timesheet module. It buys nothing else and is never required.

### 1.2 Sub-jobs, and why the hierarchy is not optional

`parent_id` exists because contracts are routinely awarded and certified in parts: a development with
three towers where each tower is taken over separately, a highway with four lots each with its own
completion date and its own liquidated damages, a framework with a job per call-off order. Modelling
those as unrelated jobs loses the consolidated cost report the board asks for; modelling them as one job
loses the per-lot certificate, the per-lot retention release and the per-lot LDs, all of which are
contractual.

So: a job may have a parent, cost and certificates attach to **any** job in the tree, and every report
takes a job and rolls up its descendants through `path`. Depth is not constrained in the schema but the
UI offers two levels and a third only on request — three levels of job plus the WBS below it (§2) is
already four levels of rollup, and beyond that people stop being able to find anything.

## §2 Classification: WBS, cost codes, and the standards that make the numbers comparable

The reason a construction system is not a generic project tracker is here. A cost has to be filed
against *what was built* and *what kind of cost it was*, using a coding scheme that is the same on the
next job — otherwise the cost history a contractor prices the next tender from does not exist.

Three structures, and keeping them separate is the design:

| Structure | Answers | Scope | Table |
|---|---|---|---|
| **WBS** | *what* is being built, and where | per job | `construction_wbs_nodes` |
| **CBS / cost codes** | *what kind* of cost this is | per tenant, shared across jobs | `construction_cost_codes` |
| **Control account** | the intersection — the thing that carries a budget | per job | `construction_budget_lines` (§3) |

That intersection is not an invention: **ANSI/EIA-748** defines the control account as the point where
scope, budget and actuals meet, and it is what makes earned value computable at all (§14).

`construction_wbs_nodes` (tenant): `job_id`, `parent_id`, `path`, `code` (`1.2.3`), `name`, `kind`
(`phase|zone|level|element|package`), `sort_order`, `is_leaf`, timestamps. Unique on `(job_id, code)`.
A WBS node is a *deliverable or a location*, never a cost type — "Tower B / Level 4 / Facade" is a WBS
node; "welding labour" is not.

`construction_cost_codes` (tenant): `parent_id`, `path`, `code`, `name`, `cost_type`
(`labour|material|plant|subcontract|other` — the standard five), `unit` (`m3`, `m2`, `t`, `hr`, `sum`),
`is_leaf`, `is_active`, `sort_order`, and then the classification mapping columns:
`masterformat_code`, `uniformat_code`, `uniclass_code`, `omniclass_code`, `nrm_code`,
`icms_category`, `icms_group`, `notes`. Unique on `code`.

### 2.1 One tree, many mappings — not many trees

A contractor is asked for the same money in four different shapes in the same month: the tender came in
**CSI MasterFormat** divisions, the QS measured it under **RICS NRM**, the design team codes information
in **Uniclass 2015** (UK) or **OmniClass** (North America), and the client's cost report has to arrive in
**ICMS 3** categories because that is what allows the number to be compared with a project in another
country.

The wrong answer is a tree per standard, and it is wrong for a reason that shows up late: every cost then
has to be classified four times, the four classifications drift, and reconciling them becomes a monthly
job for a person. The right answer is **one cost-code tree the company actually uses, with the standard
codes carried as columns on it**. Every report is then a `group by` on whichever column was asked for,
the mapping is maintained once per code rather than once per transaction, and a code that has not been
mapped to ICMS shows up as a single "unmapped" row in the ICMS report — visible, countable, fixable —
rather than as a silently wrong total.

**ICMS is the one that is not optional**, and it earns its two dedicated columns. ICMS 3 fixes Level 2
as six cost categories — **A**cquisition, **C**onstruction, **R**enewal, **O**peration,
**M**aintenance, **E**nd of life — and mandates standardised Level 3 cost groups beneath them (thirteen
under Construction, eight under Operation), specifically so that projects in different countries and
different national standards can be compared. Level 4 sub-groups are the company's own. That is exactly
the shape of `icms_category` + `icms_group` on a cost code whose own numbering is whatever the company
has always used: the local scheme survives, and the international report is a group-by.

### 2.2 The library is per tenant, not per job

Cost codes are shared across every job in the company and this is the entire point of having them. "What
did formwork to soffits cost us per square metre, across the last six jobs" is the question a contractor
prices the next tender with, and it is unanswerable the moment each job invents its own codes. A job
therefore *selects* codes rather than *owning* them: opening a budget line against a code is what puts it
on the job (§3), and a job's cost report shows only the codes it has used.

The escape hatch for the genuine case — a one-off provisional item that will never recur — is a code
flagged `is_active = false` at library level and used on one job. That is a deliberate, visible act.
Inventing a private numbering scheme per job is not available.

Reference data ships as a seeder: MasterFormat's fifty divisions and their common Level 2 titles, a
UniFormat elemental set for estimating, and the ICMS Level 2/3 spine, as an **opinionated starting
library a company edits**, not as a locked standard. Per `docs/new-module-checklist.md` §1a the seeder
is listed against the `construction` company profile and nowhere else, or `CompanyProfileTest` fails.

## §3 The job-cost ledger

This is the decision the plan turns on, and it was taken deliberately: **construction keeps its own cost
ledger and reconciles to the books, rather than adding a job dimension to `journal_entry_lines`.**

### 3.1 Why a second ledger, honestly

The dimension-on-the-line approach — which `docs/retail-stores-pos-plan.md` §2.2 chose for stores — is
simpler and has one source of truth, and if job costing were only "profit per job" it would be the right
answer here too. It is not enough for construction for three reasons that are not preferences:

- **A cost entry needs a quantity and a rate, and a journal line cannot carry one.** Rate analysis —
  *"we budgeted 4,200 per m³ of poured concrete and we are running at 5,050"* — is the entire discipline
  of construction cost control, and it is unavailable from money alone. Putting `quantity`,
  `unit_of_measure` and `unit_rate` on `journal_entry_lines` would impose construction's shape on every
  payroll and bank posting in the application.
- **Not every job cost is a general-ledger event.** Burden absorbed at a rate, internal plant charged at
  an hourly rate, overhead allocated on a basis, a notional tender comparison — these are management
  facts. Some post to the GL as recoveries; some deliberately never post. A ledger that must balance
  cannot hold the ones that never post, and inventing balancing entries for them corrupts the accounts.
- **Cost periods and fiscal periods are different clocks.** A job runs three years and reports monthly
  against a valuation date; the GL closes on a fiscal calendar. Forcing job cost onto the fiscal clock
  makes the monthly cost report a fiscal-year artefact, which is not what a certificate is measured on.

**The price of the decision is that nothing forces the two to agree**, and §4 is what pays it. If §4 is
ever descoped, this section should be descoped with it — the pair only makes sense together.

### 3.2 What a cost entry is

An immutable statement: *this much value, of this cost type, landed on this job, WBS node and cost code,
incurred on this date, in this cost period, caused by this document, and here is its relationship to the
general ledger.*

`construction_cost_entries` (tenant): `job_id` (always a cost-bearing leaf job), `wbs_node_id`
(nullable), `cost_code_id`, `job_cost_code_id` (nullable — the job's own BoQ line, for printing),
`cost_type` (**snapshotted** from the code, not read through it), `kind`
(`actual|accrual|allocation|reclass|reversal`), `amount` `decimal(15,2)` **signed**, `quantity`
`decimal(14,4)`, `unit_of_measure`, `unit_rate` `decimal(14,4)`, `incurred_on`, `posting_period` (the
first day of the cost month, a date and not a 1–12 index, for the reason `budget_lines.period_start`
already gives), `is_late_for_period`, `fiscal_year_id`, `gl_treatment`
(`mirrored|posted|pending|memo`), `journal_entry_id`, `gl_account_id`, `posted_to_gl_at`, `batch_id`,
`contact_id`, `employee_id`, `worker_id`, `is_burden`, `reverses_id`, `reversed_by_id`, `description`,
`reference`, `nullableMorphs('source')`, `created_by`, timestamps.

Three details are load-bearing rather than incidental:

- **One signed amount, not a debit and a credit.** A debit/credit pair here imports an accounting form
  that buys nothing and invites the question "which column does a credit note go in", which two
  developers will answer differently.
- **`cost_type` is snapshotted.** Re-typing a cost code in June must not restate March's labour/material
  split — the same reasoning that stores `invoice_lines.tax_amount` rather than recomputing it.
- **`gl_treatment` is a column, never inferred from `journal_entry_id IS NULL`.** `pending` (should have
  reached the GL, has not yet) and `memo` (deliberately never will) look identical as a null, and a null
  that means "we do not know which" is exactly how a sub-ledger drifts for a year unnoticed.

**The invariant**: the sum of `amount` over a job's entries, filtered by nothing but the period, **is**
the job's cost. No `is_active`, no soft delete, no current-version flag. A flag that must be filtered is
a flag somebody forgets, and the query that forgets it is a cost report that is wrong and looks fine.

`construction_cost_batches` gives bulk operations one thing to reverse — a month's overhead allocation
across two hundred codes, a labour run, an accrual and its reversal — with `kind`, `period_start`,
`journal_entry_id`, `posted_at` and `reversed_batch_id`. A reversal that has to re-find its two hundred
rows by predicate is a reversal that will one day find a hundred and ninety-nine, and nothing will say
which one it missed.

### 3.3 Corrections are reversals, with one line drawn deliberately

An entry becomes immutable when either its period is closed or `posted_to_gl_at` is set. After that a
correction is a `kind='reversal'` row with the amount and quantity negated and `reverses_id` pointing
back, plus the corrected entry where the cost was real but mis-coded.

Before that point, editing an entry in an open period is allowed and audited. Absolutism here is worse
than the rule: forcing a reversal pair for a typo made ten seconds ago produces three rows where one is
true, and a cost report full of ±5,000 pairs is unreadable. The line sits exactly where `journal_entries`
already draws it — `JournalEntryService::post()` refuses a closed fiscal year and `::reverse()` mirrors
rather than mutates.

### 3.4 Cost periods

`construction_cost_periods`: `period_start` (unique), `period_end`, `status`
(`open|closed|reconciled`), `closed_at`, `closed_by`, `reconciled_at`, `gl_control_total`,
`jc_control_total`, `difference`, `notes`.

A closed period refuses new entries. **A late supplier invoice dated into a closed month lands in the
open period with `incurred_on` preserved and `is_late_for_period = true`**, and there is a Late Costs
report. Reopening a signed-off period to slot one invoice in invalidates the WIP snapshot, the client
certificate and the GL summary that all depended on that period's total — and silently changing a closed
month is the precise failure this whole design exists to prevent.

### 3.5 Budget, commitment, actual, forecast — the four columns

`construction_budget_versions`: `job_id`, `version_no`, `name` (*Tender*, *Contract award*, *Rev 3 post
VO-12*), `kind` (`estimate|original_budget|revision`), `status`, **`is_baseline`** and **`is_current`**
(exactly one of each per job), `effective_from`, and the approval stamps.
`construction_budget_lines`: `budget_version_id`, `job_id` (denormalised for the hot query),
`wbs_node_id`, `cost_code_id`, `cost_type`, `quantity`, `unit_of_measure`, `unit_rate`, `amount`, and a
nullable `period_start` that time-phases the line.

**Versioned because earned value needs a baseline that does not move and a cost report needs a budget
that does.** Without the split, cost variance is measured against a number somebody edited last Tuesday,
schedule variance is meaningless, and the variance report answers a question nobody asked. Superseded
versions stay for comparison, which is the same reasoning `budgets.is_active` already gives.

`construction_forecast_runs` and `construction_forecast_lines` hold the surveyor's cost to complete per
control account, the method used, and the resulting forecast final cost. **A forecast is a snapshot, not
a mutable row**: the whole value of forecasting is comparing last month's estimate at completion with
this month's — *we said 4.2 in March and 4.9 in April; what moved* — and a single mutable row destroys
the only report that makes forecasting worth doing.

The report, per job subtree × cost code, for a period or to date:

| Column | Source |
|---|---|
| Budget | `construction_budget_lines` of the `is_current` version |
| Committed, open | `Σ commitment_lines.amount − Σ reliefs.amount`, approved commitments only (§5) |
| Actual | `Σ cost_entries.amount` where `kind != accrual` |
| Accrued | `Σ cost_entries.amount` where `kind = accrual` |
| Cost to complete | `construction_forecast_lines.cost_to_complete` |
| Forecast final | actual + accrued + cost to complete |
| Variance | budget − forecast final |

**The committed-aware rule: cost to complete may never be less than the open commitment on that code.** A
cost code with a purchase order worth more than its remaining budget is already overspent, and a forecast
saying otherwise is forecasting money that has already been promised away. The form defaults the figure
to the greater of remaining budget and open commitment; a lower number is accepted **with a reason** and
appears on a *forecasts below commitment* exception report.

## §4 Reconciliation to the general ledger

The load-bearing consequence of §3. A second ledger that nobody proves is a second ledger that is wrong.

### 4.1 Nothing double-posts

| `gl_treatment` | Meaning | Who posted the GL side |
|---|---|---|
| `mirrored` | The source document already posted. Job cost is a *dimension* of an existing GL cost. | Invoicing, Inventory, Payroll |
| `posted` | Construction posted it, in a summary journal. | `ConstructionGlPostingService` |
| `pending` | It should reach the GL and has not yet. | Nobody, yet |
| `memo` | Deliberately outside the GL. | Nobody, ever |

If the source is a GL document — supplier invoice, payment, stock movement, payslip — construction posts
**nothing** and mirrors. If the source is construction-only — site labour with no payroll behind it,
burden absorption, internal plant recovery, overhead allocation, the goods-received accrual, the
uncertified-work accrual, WIP — construction posts a **summary journal per period per (GL account × cost
type)** through `JournalEntryService::create()`, exactly as `InventoryService` posts its system entries.

Per-entry posting is not on the table: a hundred thousand entries a period would make
`journal_entry_lines` the largest table in the tenant and the general ledger unreadable. **What makes
summary posting safe is the batch link** — the batch carries `journal_entry_id`, every contributing entry
carries `batch_id`, and any GL line explodes into its constituents in one query. A summary posting
without that link is a number in the accounts nobody can explain, and should be treated as a defect
rather than a shortcut.

### 4.2 The reconciliation report

`construction_control_accounts` nominates which accounts are in scope, by `kind`
(`cost|wip|accrual|retention|revenue|recovery|contract_asset|contract_liability`) — a table rather than a
config list, so a company can name its own accounts and the report can name them back.

```
GL cost for the period          Σ journal_entry_lines (Dr − Cr) on control accounts of kind=cost,
                                posted entries only — the same filter FinancialReportService uses,
                                which must be matched exactly or the two sides will disagree
                                about drafts and nobody will know why
  less   GL cost carrying no job        [UNALLOCATED — shown, never spread]
  ±      reconciling items             [GRN accruals, materials on site, retention movement,
                                        internal plant recovery, burden absorbed]
= Expected job-cost total
  vs     Σ construction_cost_entries.amount where gl_treatment != 'memo'
Difference                              must be 0.00
```

Then the drill-down **by cause**, which is what makes it a tool rather than a number: entries still
`pending` by age; GL lines on a control account with no cost entry (somebody journalled a cost straight
to `5020` from the Accounting panel — by a wide margin the most common cause); cost entries pointing at a
journal entry that was later reversed or unposted (the nastiest, because the GL side was corrected in
another module and the job cost was not); **unallocated purchase invoices, rendered even when empty**;
entries against a closed job; absorption gaps; and rounding, isolated so it cannot be used to explain
anything else.

### 4.3 What happens when they disagree

`construction_reconciliations` stores each run — totals, difference, cause breakdown, `status`
(`balanced|unbalanced|accepted`), and who accepted it and why. Five mechanisms, because a report nobody
opens is not a control:

1. A scheduled `construction:reconcile` command, licence-guarded, that writes a row and notifies when
   unbalanced.
2. The period cannot be closed while unbalanced.
3. Unless a user holding `ConstructionPeriodForceClose` accepts the difference **with a stated reason**.
4. **A forced close never fudges the ledger.** No plug entry, no balancing figure. Both sides stay true
   and the difference stays visible in every later period until the cause is fixed.
5. A feature test that posts one of every source type — allocated supplier invoice, GRN accrual, material
   issue, labour with burden, internal plant, subcontract certificate with retention, overhead
   allocation — and asserts `difference === 0.00`. That is what turns this section from a claim into an
   assertion.

### 4.4 WIP, and the loss that must be taken at once

Per job per period: cost to date, estimated total cost (the approved forecast), percent complete
(cost-to-cost, surveyed, or milestone — chosen per job, because one company runs both), contract value
including **approved** variations with pending ones shown separately and excluded, revenue recognised,
billings to date, and then the two positions every construction balance sheet carries — **costs and
recognised profit in excess of billings** (a contract asset) and **billings in excess of costs** (a
contract liability). When the forecast final cost exceeds the contract value, **the whole expected loss
is recognised immediately**, never pro-rated.

`construction_wip_snapshots` stores this per job per period, and it is the one stored total in this plan
that is not a performance materialisation. The argument against the house rule in
`docs/new-module-checklist.md` §10: a WIP position is a **judgement at a point in time** — the surveyor's
forecast, the surveyed percentage, the loss provision — not a derivation from immutable facts.
Recomputing last March's WIP with today's forecast would silently restate a month that was signed off,
reported to a bank and used to compute a bonus, and the journal posted then would no longer be
explicable by any query. So the current unlocked period is computed live and the locked ones are frozen.

The WIP journal posts the **movement** from the previous locked snapshot, not the balance. Reversing last
month's whole position and re-posting this month's is a defensible alternative — `journal_entries` has a
`reversing` type already — but it produces a profit and loss whose gross figures are enormous and whose
monthly movement has to be inferred. Choose one; two code paths each choosing differently is the failure.

### 4.5 Accruals

Two, both as batched `kind='accrual'` entries: **goods received not invoiced** (Dr job cost / Cr GRNI)
and **subcontract work done not certified** (Dr job cost / Cr accrued subcontract costs). Both
**auto-reverse at the opening of the next period** rather than being matched off against the eventual
invoice, because matching an accrual line-by-line to a later invoice is the same heuristic that fails for
commitment relief, and an accrual that fails to match sits on the balance sheet forever with nobody able
to say what it is for. Reverse-and-re-accrue is self-correcting; its own failure mode — the reversal not
running, so the accrual and the real invoice both sit in the ledger and the job costs double for a
month — is why the reversal belongs to period *open* rather than period close.

## §5 Procurement, and the number a site manager actually manages by

Committed cost — money promised to somebody and not yet invoiced — is what tells a manager whether a cost
code is overspent *before* the invoice arrives. Nothing in this application holds it today (finding #4),
so this is the largest net-new schema surface in the plan.

**`construction_commitments`** is one table serving purchase orders, subcontracts and plant hire:
`number`, `type` (`purchase_order|subcontract|plant_hire|manual`), `contact_id`, `status`
(`draft|pending_approval|approved|issued|partially_relieved|closed|cancelled`), `currency_code`,
`order_date`, `required_by`, `payment_terms_days`, `retention_percent`, approval and issue stamps, and
`closed_at` + `close_reason` — because closing a purchase order with an open balance is an intentional
act with an author, never a number that quietly stops changing.

`construction_commitment_lines` carries `job_id`, `wbs_node_id`, `cost_code_id`, `product_id`
(guarded on Inventory), description, quantity, rate and amount. **The job is on the line, not the
header**: one order of rebar split across three sites is completely normal, and forcing one order per job
means either the supplier gets three orders for one delivery — which he will not honour, and the delivery
note will then match nothing — or somebody codes the whole load to one job. Both are silent cost
misallocation and the second is invisible.

One commitments table with a subcontract *terms* extension rather than two tables, because the four-column
report and the relief mechanism must behave identically for orders and subcontracts or "committed"
means two different things in one column; two tables makes every commitment query a `UNION`, and a
`UNION` is where one branch silently gains a filter the other does not and the report still renders.

**Relief is an explicit row, not a subtraction.** `construction_commitment_reliefs` records each
`receipt|certificate|invoice|cancellation|close_out` against a commitment line. The tempting alternative
— *committed = ordered − invoiced*, matched by job, code and supplier — is a heuristic that fails the
first time one invoice covers two orders or one line is part-delivered, and it fails by leaving an
over-commitment nobody can point at and nobody can clear. With explicit reliefs, open commitment is
provable as `line.amount − Σ reliefs`. **The hazard is double relief** — the goods receipt relieves, and
then the invoice for the same receipt must not relieve again. The rule: relief happens once, at the
earlier of receipt or certificate, and the invoice relieves only the unreceived balance.

The chain, and what is reused:

| Step | Reuse or new |
|---|---|
| Material requisition | new `construction_requisitions` — nothing here has a demand document |
| Supplier quote / RFQ | new, later phase. `quotations` is *outbound* and requires Invoicing; an inbound quote is a different document and the name collision is worth flagging early |
| Purchase order | `construction_commitments` type `purchase_order` |
| Goods receipt | new `construction_goods_receipts` — where cost first touches the job |
| Supplier bill | **reuse** `invoices.kind='purchase'` and `InvoiceService` |
| Job/code attribution of the bill | new `construction_invoice_allocations` |
| Three-way match | new service and report, computed |
| Subcontract | `construction_commitments` type `subcontract`, pointing at the `construction_contracts` row |

> **Resolved 2026-08-18, at the start of Phase 5, because this section and §8.1 disagreed.** §5 above says a
> subcontract is a commitment with a 1:1 *terms* extension; §8.1 says the one contracts table serves "the head
> contract and the subcontract" with `side = payable`. Both are in this document and they cannot both be the whole
> answer.
>
> They are two aspects of one agreement, and the model links them rather than choosing: **the contract row is the
> agreement** — schedule, variations, certificates, retention, which is the whole of §8–§12 — and **the commitment
> row is the money promised**, relieved as certificates are issued, which is what the four-column report's
> *committed* column reads. `construction_commitments.contract_id` is nullable and points at the contract when
> there is one.
>
> The terms extension is therefore unnecessary: a subcontract's terms are the contract's columns, which already
> carry retention, payment days, damages and the release rule. Building a second set on a commitment would be the
> "staged retention release is the fiddliest logic in the suite, so a second copy of it will diverge" failure that
> §8.1 spends a paragraph rejecting.
>
> Commitments live in `construction_costing`, which is where §18's table puts "procurement and commitments". The
> `contract_id` is unconstrained rather than a foreign key, because costing does not require
> `construction_contracts` — the same treatment `construction_jobs.project_id` gets, and for the same reason.

A goods receipt does three things: relieves the commitment, raises an accrual cost entry at order rate,
and — only when the delivery is into a site store rather than straight to the work face, and only when
Inventory is licensed — writes a stock movement (§6).

**`construction_invoice_allocations` is a table, not three columns on `invoice_lines`.** One line —
*"rebar, 12 t"* — is routinely split across two jobs and three cost codes; columns force a 1:1 and the
workaround is splitting the invoice line, which makes the document this application prints disagree with
the one the supplier sent, discovered months later during a dispute. It also keeps construction's schema
inside construction's tables: three construction-shaped columns on `invoice_lines` would make Invoicing
carry this module's schema and would point a `ModuleBoundaryTest` coupling the wrong way.

**The cost of that choice, stated plainly: a purchase invoice can be posted with no allocation at all,
and the general ledger is perfectly correct while the job is under-costed.** It is the single most likely
silent failure in the module. Two things answer it and both are structural rather than procedural — an
*Invoices awaiting allocation* queue as a screen people work from, and a permanent, always-rendered
section of the reconciliation report showing zero when there is nothing rather than being hidden.

Three-way match — ordered against received against invoiced, per commitment line — is **computed, not
stored**, with one exception: accepting a variance is a human decision, so `match_status = 'accepted'`,
who accepted it and the reason *are* stored. A decision with no record is not a control. Tolerances live
in config overridable by settings, the `PayrollAccounts` pattern.

## §6 Site stores and material issue

Three paths, in the order most contractors actually use them.

**Direct to site is the default and touches no stock at all.** A goods-receipt line marked direct writes
a cost entry and no stock movement. This path works with Inventory unlicensed, which is what keeps
construction sellable to a contractor who buys everything to site and tracks no stock — which is most of
them, most of the time.

**A site store needs stock to have a location, and it does not have one** (finding #3). This is the same
gap `docs/retail-stores-pos-plan.md` finding #1 names, and the two plans now collide on one migration.
That plan proposes `stock_movements.store_id → stores`, backfilled and made non-null, and its own risk
section calls the backfill a one-way door. **If it lands in that form, construction site stores get two
bad options**: create fake `stores` rows — a building site in a table carrying till settings, a sales
channel and a POS registration number — or add a *second* location column, at which point on-hand is a
sum over two nullable dimensions, wrong at every location and correct in total, which is the hardest
class of wrong to notice.

**Decided 2026-08-17 — adopted, and written into `docs/retail-stores-pos-plan.md` §2.1 and its Phase 0 as
well, which was the actionable half of this note. The location belongs to Inventory.** A `stock_locations` table owned by Inventory (`code`, `name`, `kind` of
`warehouse|shop|site|van|transit`, address, `inventory_account_id`), `stock_movements.stock_location_id`
backfilled once, `stores.stock_location_id` in retail and `construction_jobs.stock_location_id` here. One
column, one backfill, one change to the valuation API, and both modules are served without either
depending on the other. **This is the highest-value cross-plan note in this document**, and two plans each
assuming the other will raise it is how neither does.

The issue document is construction's regardless. `construction_material_issues` and its lines carry job,
WBS node, cost code, product, quantity, FIFO unit cost, returned quantity, wastage quantity and reason,
and two names — who issued and who received, because the paper docket has two signatures. None of that
belongs on `stock_movements`, which is deliberately thin and already has `nullableMorphs('source')` for
exactly this division of labour: the module owns the document, Inventory owns the movement, which is what
`InvoiceService::recordMovement()` does today.

One coordinated change: `stock_movements.type` is `purchase|sale|adjustment`. Construction needs `issue`
and `return`; the retail plan needs `transfer`, `waste` and `count_adjustment`. Booking a controlled site
issue as an "adjustment" makes the shrinkage report meaningless, because adjustments are supposed to be
the *unexplained* ones. Expand the enum once, in one migration, covering both plans.

**Materials on site** — delivered, costed, not yet consumed — is exactly the gap between receipt and
issue, and it is one query. It is also a line on every payment certificate (§10), which is the second
reason receipt and issue are separate documents.

## §7 Labour and plant

### 7.1 Construction owns its labour records, and the reason is not tidiness

Three facts force it. `timesheet_entries` bills and never costs — the rate ladder resolves what time is
*billed* at, by its own migration comment, and there is no cost rate and no burden anywhere.
`timesheet_entries.project_id` is **not null** and constrained to `projects`, so a construction job
cannot appear on one at all without the Projects module. And the decisive one: **on a site, most hands
are not employees** — no payslip, no login, paid weekly through a gang leader. Requiring an `Employee`
row per labourer would make Employees a hard dependency and would put three hundred people who are not
employed into the HR register, where Leave, Payroll and Lifecycle would then all see them.

So `construction_workers` (with a nullable `employee_id` for the ones who *are* employees, and a nullable
`subcontractor_contact_id` for labour supplied through a gang leader), `construction_trades` with default
codes and rates, and `construction_labour_records` carrying job, WBS node, cost code, date, normal and
overtime **minutes** (minutes, like `timesheet_entries` and attendance, because a rate multiplied by a
rounded decimal of hours accumulates visible error across a month), the overtime multiplier, and — the
important part — `cost_rate_per_hour` and `burden_percent` **snapshotted at approval**.

Where Timesheets *is* licensed, its entries import into labour records rather than being read in place,
carried on the `source` morph. Billing keeps its ladder; costing gets its own.

### 7.2 A dated rate table, not a rate column

`construction_labour_rates` resolves **job+trade → job → worker/employee → trade → company default**,
each row with `effective_from` and `effective_to`.

A wage revision effective the first of April must not restate March's job cost. A `cost_rate_per_hour`
column on the worker does exactly that, silently, the moment somebody edits it: every historical record
recomputes, every closed period's cost changes, and there is no journal, no audit and no report of what
moved. The snapshot on the record is the second line of defence; the dated table is the first.

### 7.3 Burden and internal plant must be absorbed, or both ledgers diverge

Burden is written as its **own** entry against the **same** cost code, flagged `is_burden`, so "labour
cost" stays one number and burden stays separable. The rule that matters: **burden charged to jobs must
credit a Labour Burden Absorbed account**, against which the real statutory and welfare costs accumulate
through payroll, with the difference showing as over/under absorption on the profit and loss. Charge it
and never absorb it and job cost exceeds GL cost by exactly the burden, growing every month, with no
error anywhere — §4 is the only thing that would find it.

> **Resolved 2026-08-19, at the start of Phase 7b, because this section and §3.2 disagreed about `memo`.**
> §3.2's enum comment offers *"burden at a rate, a notional comparison"* as the examples of `memo` — "it
> deliberately never will" reach the GL — and the migration and `CostEntry::GL_MEMO` both repeat it, adding
> internal plant. But this section requires burden to credit Labour Burden Absorbed, and two paragraphs
> below it requires internal plant to credit Plant Internal Hire Recovery. Both cannot be true.
>
> **§7.3 wins, and the examples were the error.** They are the two things §7.3 spends its whole length
> insisting must post, and §4.2's reconciling-items list already names "internal plant recovery, burden
> absorbed" — which is the list of things the GL *has* and the job-cost total must be adjusted for. A
> `memo` burden would not appear in §4.2's `gl_treatment != 'memo'` sum at all, and the divergence this
> section describes would be exactly what the design produced.
>
> So burden and internal plant are **`pending` until Phase 11's posting service runs, then `posted`**.
> `memo` keeps its meaning and loses its examples: it is for a management figure with no GL side by
> nature — §3.1's notional tender comparison, and an overhead allocation a company chooses not to post.
> The comments naming burden and plant have been corrected in `CostEntry` and in the migration, because a
> wrong example in the schema is what a later reader will copy.

Plant is the same shape and the same trap. `construction_plant_items` (owned, hired, or hired with
operator; linked to `fixed_assets` when owned) and `construction_plant_logs` (working, idle and standby
units, meter readings, fuel, operator, downtime reason, rate snapshotted). **Charging a job internal hire
for a company-owned excavator must credit a Plant Internal Hire Recovery account**, against which
depreciation — `DepreciationService` already exists — fuel, repairs and the operator accumulate. Debit
the job with no credit and the fleet looks free while every job looks expensive.

Hired plant runs the ordinary chain, and the plant log becomes the *check*: days on site times rate
against the invoice, which is a two-way match that catches the classic over-billing of plant left
standing after it was collected.

## §8 The head contract, under both standards

Three decisions carry this half of the plan, and they are worth stating before the tables.

**Cumulative is stored; the period movement is derived.** Both a FIDIC Interim Payment Certificate and
an AIA G703 are cumulative documents — *"total completed and stored **to date**"*. Store the period
amount and sum for the cumulative, and a corrected earlier certificate silently breaks every later
total. Store the cumulative and a correction is self-healing: the next certificate's "this period"
figure absorbs it, which is exactly what happens on paper.

**One set of tables, two vocabularies, and — the highest-leverage decision here — three neutral date
columns.** Taking-Over under FIDIC and Substantial Completion under AIA are the same real-world event;
so are expiry of the Defects Notification Period and the end of the correction period. One
`practical_completion_date` plus `defects_period_days` makes retention release **one function with a
branch** instead of two implementations that drift.

**Draft computes, issue freezes.** The house rule is that totals are computed, and it protects *running
balances*. A payment certificate is not a running balance; it is a statement of a moment handed to a
third party. This codebase already makes exactly this exception and says why —
`Invoice::exchangeRate()` reads the stored column "because a rate recorded later for the invoice date
must not silently restate an invoice that has already been issued". Certificates, certificate lines and
variation amounts follow the same rule.

### 8.1 One table for the head contract and the subcontract

`construction_contracts` (tenant): `job_id`, **`side`** (`receivable|payable` — we bill the employer, or
we pay a subcontractor; the only structural difference between the two halves of this module),
`contract_standard` (`fidic|aia|custom`), `fidic_book` (`red|yellow|silver|green|gold|pink`, driving
printed clause references and nothing else), `contract_number`, `contact_id`, `parent_contract_id` (a
subcontract names the head contract above it, which is what makes back-to-back retention reportable),
`title`, `scope_summary`, **`measurement_basis`**
(`lump_sum|remeasured|mixed|cost_plus|target_cost`), `classification_system`, `currency_code`,
`exchange_rate`, then the money — `contract_sum`, `retention_percent`, `retention_limit_percent`,
`retention_limit_amount`, `retention_release_rule`
(`fidic_two_stage|aia_substantial|single_stage|custom`), `retention_first_release_pct`,
`materials_retention_percent`, `advance_payment_amount`, `advance_recovery_start_pct`,
`advance_recovery_rate_pct`, `liquidated_damages_per_day`, `liquidated_damages_cap_pct`,
`payment_terms_days`, `certification_period_days`, `minimum_certificate_amount` — then the dates:
`contract_date`, `commencement_date`, `time_for_completion_days`, `contract_completion_date`,
`extended_completion_date`, `practical_completion_date`, `defects_period_days`,
`final_completion_date`, and `status`.

**`measurement_basis` is not derived from `contract_standard`, and conflating them is a real error**:
AIA contracts are routinely unit-price, and FIDIC Yellow is lump sum. It is the measurement basis, never
the standard, that decides whether quantities are remeasured.

**Expiry of the defects period is computed** — `practical_completion_date + defects_period_days` — and
never stored. A stored expiry date is a date that stops agreeing with the completion date somebody
corrected last week, and the disagreement is worth money.

**One table rather than two** because the arithmetic is mirrored: a subcontract has a schedule,
variations, applications, certificates, staged retention, and a certificate that becomes a *purchase*
invoice instead of a *sale* invoice. Two tables means two variation tables, two certificate tables and
two retention ledgers — and staged retention release is the fiddliest logic in the suite, so a second
copy of it will diverge. The precedent is exact and already in this repository: `invoices.kind` is one
table for receivable and payable, with `InvoiceService` branching only at the posting step.

`construction_contracts.contract_standard` is **copied down from the job and frozen on first
certification**. It drives the numbering series, the retention release rule and the printed form of
documents already issued; flipping it in month fourteen would retroactively re-label certificates one to
thirteen, re-print them on a different form and change how much retention is releasable today, with no
event recording any of it. The same argument `quotations` makes for supersession — *"a quote is a
document somebody has in their inbox"*. It also lets a FIDIC Red head contract sit above bespoke
subcontracts, which a job-level-only setting cannot express at all.

### 8.2 The SOV line and the BOQ item are the same row

`construction_contract_items`: `contract_id`, `parent_id` (BoQ sections, G703 subtotal groups), `item_no`
(the printed line number — `2.1.4` or `03 30 00`), `sort`, `wbs_node_id` and `cost_code_id` (**the join
to job cost**), `classification_code`, `description`, `item_type`
(`measured|lump_sum|provisional_sum|prime_cost_sum|dayworks|contingency|milestone|advance|adjustment`),
`unit`, `quantity`, `rate`, **`scheduled_value`**, `retention_applies`, `materials_allowed`,
`source_variation_id`, `supersedes_item_id`, `is_active`.

**Which fields differ by standard: none.** AIA fills `item_no`, `description` and `scheduled_value` and
leaves unit, quantity and rate null. FIDIC fills unit, quantity and rate and derives the scheduled value.
The difference is which columns are null, not which table you are in — and that is the proof the
dual-standard model holds rather than a claim about it.

`scheduled_value` is recomputed from quantity × rate **while the contract is draft** and frozen at
execution. It is the figure the client signed, and it must not move when somebody edits a quantity on a
remeasured line — otherwise G703's "work completed from previous applications" can exceed its
"scheduled value", and the form becomes arithmetically impossible.

### 8.3 What `contract_standard` actually drives

A `ContractVocabulary` value object, keyed on standard. Nothing else in the module branches on it.

| Concept | `fidic` | `aia` | `custom` |
|---|---|---|---|
| Item schedule | Bill of Quantities | Schedule of Values | Contract Schedule |
| Change | Variation (VO) | Change Order (CO) | Change |
| Contractor's document | Statement | Application for Payment | Payment Application |
| Certifier's document | Interim Payment Certificate | Certificate for Payment | Payment Certificate |
| Certifier | Engineer | Architect | Certifier |
| Completion event | Taking-Over Certificate | Substantial Completion | Practical Completion |
| Defects | Defects Notification Period | Correction Period | Defects Period |
| Final | Performance Certificate | Final Completion | Final Completion |
| Numbering | `IPC-{n}` / `VO-{n}` | `APP-{n}` / `CO-{n}` | `PC-{n}` / `CH-{n}` |
| Retention default | two-stage, 50% at Taking-Over | released at Substantial Completion | single stage |

Numbering follows `Quotation::nextNumber()` in shape but is scoped **per contract rather than per year**:
certificate numbering restarts at one for each contract and must have no gaps, because a missing
certificate number is a question at adjudication.

### 8.4 Worked example — one FIDIC certificate, one AIA application, the same tables

A remeasured FIDIC Red contract, sum 500,000,000, retention 10% capped at 5% of the sum, advance
50,000,000 recovered at 25% once certified value passes 10%.

Certificate 7 header, all frozen snapshots: `contract_sum_original` 500,000,000,
`variations_net_to_date` +13,020,000 (**agreed** variations only), `contract_sum_to_date` 513,020,000,
`gross_work_to_date` 212,400,000, `gross_materials_to_date` 3,200,000, `gross_value_to_date`
215,600,000. Then `construction_certificate_deductions`, every one a row and every one signed negative:
retention −2,140,000, advance recovery −5,350,000, an NCR deduction under clause 14.6 −850,000, and
previously certified −184,900,000, leaving `current_due` 22,360,000.

**Every deduction row is a movement, and the previously-certified row is the previous GROSS.** That sentence
was missing, and its absence was a real defect rather than a documentation gap. The rows above are *this
period's* retention and *this period's* advance recovery, so the figure they net against must be one that
has never had retention taken out of it. Netting a movement against the previous **net cash** — which has
already had its own retention removed — hands that retention back, and nothing puts it back again. The
contract then over-certifies by exactly the prior retention from the second live certificate onward, while
`retention_to_date` and the retention register both go on saying the right thing: two of the company's own
records disagreeing, neither wrong on its own terms.

The figures above were the movement ones all along. 215,600,000 − 2,140,000 − 5,350,000 − 850,000 −
22,360,000 = 184,900,000, so the 184,900,000 in that row is the previous **gross** — only the label said
otherwise. It is stored as `previous_gross_value_to_date`, **beside** `previously_certified` rather than
instead of it, because the printed G702 line 7 wants the net figure and the arithmetic wants the gross. Two
questions, two columns, each named for its own answer.

The identity that holds this together, and the one to assert rather than reason about: over any run of live
certificates, **Σ `current_due` = gross certified − retention held**. Each certificate can look defensible
on its own and still break it.

The AIA form reads off the same columns:

| G703 column | Source |
|---|---|
| A, B, C — item, description, scheduled value | `contract_items.item_no`, `.description`, `.scheduled_value` |
| D — work completed from previous applications | `certificate_lines.previous_work_value` (a frozen snapshot) |
| E — work completed this period | `cumulative_work_value − previous_work_value` |
| F — materials presently stored | `certificate_lines.cumulative_materials_value` |
| G — total completed and stored to date | D + E + F |
| H — balance to finish | C − G |
| I — retainage | `certificate_lines.line_retention` |

| G702 line | Source |
|---|---|
| 1 Original contract sum | `certificate.contract_sum_original` |
| 2 Net change by change orders | `certificate.variations_net_to_date` |
| 3 Contract sum to date | derived |
| 4 Total completed and stored to date | `certificate.gross_value_to_date` (= Σ column G) |
| 5a / 5b Retainage on work / on stored material | deduction rows, split by basis |
| 5 Total retainage | `certificate.retention_to_date` |
| 7 Less previous certificates | `certificate.previously_certified` — net; the arithmetic nets against `previous_gross_value_to_date` |
| 8 Current payment due | `certificate.current_due` |

The FIDIC certificate needed five header figures and a deductions child table. The AIA certificate needs
the identical five. The only figure FIDIC uses that AIA does not — advance recovery — is a deduction
*row*, not a column, so an AIA certificate simply has no such row. That is the whole of the dual-standard
claim, discharged.

## §9 Variations and change orders

One table, `construction_variations`: `contract_id`, `variation_number`, `origin`
(`employer_instruction|engineer_instruction|architect_supplemental|contractor_proposal|rfi|ncr|site_condition|design_change|provisional_sum_expenditure|dayworks|claim|compensation_event`),
a `source` morph pointing at the RFI, NCR or instruction that caused it, `title`, `description`,
`justification`, `valuation_method`
(`rates_in_contract|pro_rata_rates|new_rate_agreed|dayworks|lump_sum|cost_plus_percentage` — FIDIC 12.3
and 13.3), `status`, `quoted_amount`, `assessed_amount`, `approved_amount`,
**`is_price_provisional`**, `provisional_confidence`, `time_impact_days`, `time_granted_days`,
`eot_status`, and the instruction, submission, pricing, approval and incorporation stamps.

`construction_variation_items` carries `action` (`add|omit|remeasure|rate_change`), the target
`contract_item_id`, the new item's description, unit, quantity, rate and amount, and — the audit trail —
`previous_quantity` and `previous_rate`.

```
draft ─► submitted ─► priced ─┬─► approved ──────────────────────► incorporated
                              ├─► approved_in_principle ─► priced ─► approved ─► incorporated
                              └─► rejected
```

**`approved_in_principle` with `is_price_provisional` is the state construction actually lives in**:
instructed, work proceeding, price disputed for four months. Modelling it as either approved or not
forces a choice between certifying money nobody agreed and forecasting a cost the job is already
incurring.

**The rule that follows is the sharpest one in this section.** A provisionally priced variation is
**excluded from the certified contract sum and included in the forecast**. Two named scopes on the
model — `agreed()` (`approved` and not provisional) and `forecast()` (`approved` or
`approved_in_principle`) — and **no bare `where('status', 'approved')` anywhere in the module**. The
certificate's `variations_net_to_date` and G702 line 2 read `agreed()`; the cost report reads
`forecast()`. One boolean, two audiences, and conflating them is how a job reports a margin it does not
have for two quarters running.

**Approval writes items; it never edits them.** An `add` creates a contract item carrying
`source_variation_id`, which is exactly how AIA prints change-order lines appended to the G703. An `omit`
creates a **negative** scheduled value rather than reducing the original — reducing the original destroys
the audit trail *and* breaks certificates already issued, because column D would then exceed column C,
which is impossible on the face of the form. A negative line is ugly on the page and correct in the
ledger; the print layer may net the pair. `remeasure` and `rate_change` are the one permitted in-place
edit, and they are safe precisely because on a remeasured contract the quantity was always provisional —
it is a bill of *approximate* quantities — and every certificate line carries its own frozen cumulative
value regardless.

**Provisional and prime cost sums** are item types, and expending one is a variation with
`origin = provisional_sum_expenditure` that omits the sum in full and adds the actual instructed work.
That is what FIDIC 13.5 requires, and it is what keeps the contract sum from double-counting.

**Dayworks** are priced from the site diary — labour hours by trade, plant hours working and idle,
materials — through a `DayworksPricingService` that reads a date range of daily-log children and
produces a priced variation. Guarded on the field module: without it, dayworks are typed by hand and the
action is absent rather than broken.

## §10 Progress, certification and payment

### 10.1 Two documents, because they are two documents

`construction_progress_claims` is what the contractor submits; `construction_payment_certificates` is
what the certifier issues. FIDIC clause 14 makes them different documents by different parties with
different dates and different legal effect — time bars run from the statement, the payment period runs
from the certificate — and one row cannot hold two issue dates and two authors honestly. On the payable
side the subcontractor's application arrives and we certify a *different* number, and **applied versus
certified is the single figure every commercial manager asks for**, visible only if both survive.

The strong counter-argument is AIA, where G702 is literally one form serving as both, with the architect
striking through and initialling the certified figure. The answer: G702 is *printed* as one form, and two
tables print it as one form by joining. One table cannot print a FIDIC certificate that certifies a
figure different from the statement without inventing shadow columns — so two tables serve both standards
and one table serves only one of them.

`payment_certificates.progress_claim_id` is **nullable**, because clause 14.6 permits the Engineer to
certify without a conforming statement. The nullability costs nothing; its absence would be a hard block
on the FIDIC path.

### 10.2 Value is authoritative; percent and quantity are inputs

A claim line carries `measurement_input` (`percent|quantity|value|milestone`) — **which one the person
actually typed** — alongside `cumulative_percent`, `cumulative_quantity`, `cumulative_work_value` and
`cumulative_materials_value`. A lump-sum line is claimed as a percent, a remeasured line as a quantity, a
milestone line as nothing-or-everything; all three resolve to a value, and the value is what the
certificate sums.

Percent alone breaks the moment a variation grows a remeasured line's quantity: the stored percent is now
against a stale denominator, so the line reads 87% while the money is fully certified. Quantity alone has
nothing to say about a lump-sum line. Recording which input produced the value is what lets a later
re-derivation reproduce the person's intent.

The certificate freezes `previous_work_value` and `previous_materials_value` per line as a **snapshot of
the prior certificate**, so column D prints without joining to a certificate that may since have been
voided.

### 10.3 Every deduction is a row

`construction_certificate_deductions`: `kind`
(`retention|retention_release|advance_recovery|ncr|liquidated_damages|back_charge|contra_charge|previous_certificates|tax_withheld|unfixed_materials_adjustment|other`),
`description`, a **signed** `amount` (negative reduces the payment — one convention, stated once), a
nullable `source` morph pointing at the NCR or the back-charge or the retention movement, a nullable
`account_id` posting override carried through to the invoice line, `is_automatic`, and `approved_by`.

Generalising the bottom half of the certificate into one child table is what makes an NCR deduction
traceable to the NCR, advance recovery auditable against the contract terms, and liquidated damages a
first-class fact rather than a note in a memo field.

Statutory payment regimes are configuration on the contract, never code: `payment_terms_days` and
`certification_period_days` express FIDIC's 28 and 56 days, NEC4's much shorter periods, and — with the
notice documents of §15 — the UK Construction Act's payment notice and pay-less notice clocks. A
hardcoded statute is wrong the day it is amended, and nobody notices.

### 10.4 Where construction stops and `invoices` takes over

**Sharp boundary: construction owns everything up to and including the certificate. The certificate
produces a *draft* invoice and stops.** The precedent is verbatim in this repository —
`QuotationService::convertToInvoice()` "deliberately stops at draft… issuing stays the deliberate act it
already is", and `quotation.invoice_id` is what prevents a second conversion. Copy both.

**One invoice line per deduction group, never one per contract item.** A four-hundred-item bill would
otherwise produce a four-hundred-line invoice with uniform tax treatment, and the client's accounts
department reconciles against the certificate anyway. So a receivable certificate produces:

| Line | Amount | Account |
|---|---|---|
| Work executed to 30 Jun per IPC-007 | +30,700,000 | contract revenue |
| Less retention @ 10% (cl. 14.3) | **−2,140,000** | **retention receivable — an asset** |
| Less advance payment recovery (cl. 14.2) | −5,350,000 | advance payment received — a liability |
| Less deduction, NCR-0012 (cl. 14.6) | −850,000 | contract revenue |

**Invoice gross with retention as an asset, never net.** Retention is money earned and contractually
owed, merely not yet payable. Invoicing net understates revenue and turnover by up to a tenth for the
whole life of the job, and then makes the release invoice look like revenue recognised in a period when
no work happened — precisely the misstatement an audit looks for. `invoice_lines.account_id` exists for
exactly this, described in its own migration as the posting override for non-product lines.

**One prerequisite in another module, and it is a real latent bug rather than a nicety.**
`InvoiceService::saleEntryLines()` flips the leg for a negative line — its comment explains that booking
it as a negative credit "would be dropped by `postSystemEntry`'s filter and leave the entry short by that
amount". `purchaseEntryLines()` does **not** do this, and `postSystemEntry()` filters legs to those with
a positive debit or credit. So **a negative line on a purchase invoice is silently dropped** and the
imbalance is absorbed by the balancing fixer into whichever leg it lands on. Nothing exercises it today
because no current caller writes one; the subcontract certificate of §12 is the first thing that will.
It is a three-line change in Invoicing plus a test, and it belongs **before** the payable path is built,
not discovered during it.

Tax sits on the gross line only, which is the right treatment for retention as a timing difference in
most regimes — and is a **jurisdiction question to confirm rather than a settled one**, alongside the FBR
e-invoicing fields that then apply to a certificate invoice like any other.

### 10.5 The printed forms, under Dompdf

The engine is chosen at runtime — Browsershot where Node is present, Dompdf otherwise — and the view
receives `$pdfEngine` with per-engine override partials included in the head. So every construction form
is authored once and renders acceptably under Dompdf and beautifully under Browsershot; the fallback is a
stylesheet, never a different document.

G702 is easy: a two-column header and a nine-line numbered summary, and the existing `dompdf-invoice`
partial already solves the flex-header problem with block-level children at 49%.

**G703 is the hardest page in this plan** — eleven columns, landscape, paginating over dozens of pages —
and the answer is **server-side pagination**: `table-layout: fixed` with explicit percentage widths, the
lines chunked in PHP at roughly twenty-two rows a page, each chunk its own table inside its own
page-break div, with a *brought forward* row at the top and a *carried forward* subtotal at the bottom of
every page.

That is not a workaround; it is what a printed bill of quantities has always looked like, and it buys
four things Dompdf cannot otherwise give: `page-break-inside: avoid` on a row is unreliable and chunking
removes the dependency; `<thead>` repetition is version-sensitive and **fails silently**, so the header
simply does not appear on page two of a forty-page continuation sheet and nobody looks until the client
does; "page 3 of 7" needs no engine feature when it is printed from the chunk index, where Browsershot
would need a header template and Dompdf a canvas callback and `PdfDocument` exposes neither; and the
output is **byte-comparable across engines**, which matters because a certificate that paginates
differently depending on whether the server happens to have Node installed is a certificate that cannot
be reissued identically — and reissuing identically is the entire point of a certificate.

Beyond that: `DejaVu Sans`, which ships with Dompdf and is already used, and no webfont; long
descriptions truncated to a character budget with a continuation row, because Dompdf's `text-overflow`
support will not save you; minimal shading, because half these forms still travel by photocopier; and the
currency stated once in the column header rather than in every cell.

**One legal note that belongs in the view's docblock, not in a commit message.** AIA G702 and G703 are
copyrighted documents whose reproduction the AIA licenses. Reproduce the **content and column
structure** — which is the de facto industry information set — under our own layout, titled *Application
and Certificate for Payment* and *Continuation Sheet*, and never as "AIA Document G702". Put the reason
where the next person to make it look more like the real thing will read it first.

FIDIC prescribes the *content* of clause 14.6 and not a layout, so an interim certificate is a portrait
summary plus an annexed measurement schedule — which means **one Blade component serves both standards**,
with the column set selected by `contract_standard` and the identical chunking underneath.

## §11 Retention is a ledger, not a balance

`construction_retention_movements`: `contract_id`, `payment_certificate_id` (nullable — held movements
name a certificate, releases often do not), `kind`
(`held|released|forfeited|substituted_by_bond|reinstated|adjusted`), `stage`
(`interim|first_release|final_release|early_release`), a **signed** `amount`, `basis_gross` and
`rate_applied` snapshotted so the movement is reproducible, `cap_reached` (why a movement is less than
rate × basis), `due_on` computed at the time of writing, `released_on`, `invoice_id`,
`certificate_deduction_id`, `security_id` for a bond that substituted for cash, `reason` and
`approved_by`.

This is consistent with the computed-not-stored rule rather than an exception to it: **the balance is
still computed** as the sum of movements. What is stored is the *events* — and they genuinely are not
derivable from certificates the moment any of the following happens, all of which are ordinary: a bond
substitutes for cash; sectional taking-over releases early on part of the works, which FIDIC 14.9
explicitly contemplates; part is forfeited against uncorrected defects; the parties agree a one-off
adjustment. Those are decisions, not arithmetic.

Release rules, one function with a branch:

- **`fidic_two_stage`** — the first release fires when `practical_completion_date` is set and pays out
  `retention_first_release_pct` (50 by default) of the balance; the second fires at
  `practical_completion_date + defects_period_days`, computed and never stored, or earlier if the
  Performance Certificate is issued.
- **`aia_substantial`** — the first release at substantial completion pays the balance **less a
  punch-list holdback**, conventionally a multiple of the open value of items flagged as affecting
  completion; the remainder releases at final completion. Guarded on the field module: without it the
  holdback is zero and the release is full, **with the reason stated on the movement** rather than
  silently.
- **`single_stage` / `custom`** — one or N stages with typed percentages and trigger events.

**`RetentionService` is the only writer.** Certificates never write movements directly, and a nightly
command reconciles the sum of movements against the latest certificate's `retention_to_date` and
notifies rather than throws. Two write paths, one forgotten, and nobody reads both registers in the same
week — that is how the two figures come to disagree by an amount neither party can explain at final
account.

**NEC4 absorbs into this model later without a schema change**, which is worth knowing before someone
asks. Compensation events are variations with that origin; the quotation-and-assessment shape is already
`quoted_amount` / `assessed_amount` / `approved_amount` with the time pair beside it, and the Project
Manager's own assessment is simply an assessed amount differing from the quoted one; Option X16 retention
is a two-stage release with different percentages; the shorter payment periods are the two day-count
columns. The one thing that would not have absorbed is Option A's activity schedule, which pays only for
*completed* activities and admits no percentage — which is why `measurement_input` carries a `milestone`
value now, unused, rather than needing a migration later. What would genuinely be new is NEC4's early
warning register, and that is a small new table rather than a change to any of this.

## §12 Subcontracts, and compliance that blocks

Everything in §8–§11 with `side = payable`, plus three things that only exist downward.

**Compliance documents.** `construction_compliance_documents`: `contact_id`, a nullable `contract_id`
(some obligations are company-level and some contract-specific), `kind` — general liability, workers'
compensation, professional indemnity and contract works insurance; conditional and unconditional lien
waivers, progress and final; certified payroll; prequalification; trade licence; tax registration; bond;
safety plan; method statement; training matrix — `scope` (`company|contract|period`, because a lien
waiver is per payment period and an insurance certificate is per policy period), `period_start`,
`period_end`, **`covers_certificate_id`** (the waiver against *this* payment), `reference`, `issuer`,
`issued_on`, `effective_from`, `expires_on`, `amount_covered`, `received_on`, `verified_by`,
`verified_at`, the override trio `waived_by` / `waived_at` / `waiver_reason`, `document_id`, and
`expiry_notified_at_days`.

`construction_compliance_requirements` says which kinds are required, per contract or as a company-level
template, and what each one `blocks` (`none|certification|payment|both`) with a `grace_days`.

**Status is computed and never stored.** This is the most dangerous silent failure on the payable side: a
row with a stored `status = 'verified'` and an `expires_on` three months in the past pays a subcontractor
with no cover, and the screen says everything is fine. Derive it from the dates against the date being
asked about. `expiry_notified_at_days` is copied straight from `employee_documents`, whose own migration
explains it — *"which threshold has already been warned about, so the next run only notifies when the
answer changes"* — and the daily command follows `EmployeeDocument::daysUntilExpiry()`, which is signed
rather than clamped because "expired forty days ago" is a different problem from "expires in forty days".

**Compliance blocks certification, in the service, not the form.** Two reasons. A rule enforced only in a
Filament form is a rule that a queue job, a console command or any future API bypasses in complete
silence — and the checklist is explicit that workflow transitions belong in services with the action as a
thin wrapper. And blocking at *certification* rather than at *payment* is the difference between "you may
not yet certify this" and "here is an approved payable in the ledger that finance cannot pay" — the
second is a worse state, because the liability already exists and the stuck payment has no owner. The
override is a permission plus a mandatory reason recorded on the certificate: a system with no override
is a system people work around with a spreadsheet, and then the register is decorative.

**Back-charges.** `construction_back_charges`: the subcontract, the job, `kind`
(`materials|labour|plant|cleanup|rework|damage|ncr_rectification|schedule_recovery|attendance|welfare`),
a `source` morph to the NCR or punch item or diary entry, `description`, `amount`, `markup_percent`,
`total_amount`, `status` (`draft|notified|disputed|agreed|applied|withdrawn`), **`notified_on`** with its
notice document, the dispute fields, and `applied_certificate_id` — which is to say it becomes a
certificate deduction row of kind `back_charge`. `notified_on` matters more than it looks: almost every
subcontract requires notice before a back-charge may be deducted, and an incurred-but-unnotified
back-charge is money the company will not get and does not yet know it has lost.

## §13 Schedule, milestones and extension of time

**Store the programme; never solve it.** Build activities with baseline, planned and actual dates,
percent complete, stored predecessors, milestones and delay events. Import Primavera XER and P6 XML and
MS Project XML into the same tables, keyed on an `external_id`. Do not build a forward and backward pass,
critical path, float calculation, resource levelling or a Gantt editor.

Contract administration needs exactly four things from a programme: a milestone with a contractual date,
so extension of time and liquidated damages have something to move; planned against actual and percent
complete, so a schedule index and a look-ahead exist; somewhere to hang a delay event; and an activity
identifier that an RFI, a submittal and a variation can point at. None of those needs critical-path
calculation.

Building CPM is not the expensive part — **keeping it in step with P6 is.** Every contractor large enough
to be running FIDIC already owns P6 or Asta, and the *contractual* programme lives there because that is
what was submitted and accepted. A second scheduler here that disagrees with the submitted programme is
worse than no scheduler at all: it manufactures a number the quantity surveyor will quote in a claim and
the planner will not recognise, and the disagreement stays invisible until an adjudication. Conversely,
storing nothing means extension of time has no baseline, the look-ahead has no source, and no schedule
index can be computed. So predecessors are stored so an imported network round-trips and a look-ahead can
show what is blocking, **and no date is ever calculated from them**; `is_critical` and `total_float_days`
are *imported*, not derived, and the model's docblock says so in its first paragraph.

`construction_activities`: `job_id`, `external_id`, `source` (`manual|p6_xer|p6_xml|msp_xml|asta`),
`wbs_node_id`, `parent_id`, `code`, `name`, `activity_type`, `is_contract_milestone`, `baseline_start`
and `baseline_finish` (the *accepted* programme), `planned_start` and `planned_finish` (the current one),
`actual_start`, `actual_finish`, durations, `percent_complete`, `budgeted_value`, `total_float_days`,
`is_critical`, `responsible_contact_id`, `milestone_payment_item_id` (a milestone that gets paid),
`ld_applies` (a milestone that costs money when missed), `baseline_revision` and `data_date`.

`budgeted_value` is deliberately **not** reconciled to contract items. The programme and the bill are two
different decompositions of the same job, and forcing agreement between them produces a fiction that
somebody then has to maintain.

`construction_delay_events`: `job_id`, `contract_id`, `reference`, `title`, `description`,
`cause_category` (employer risk, contractor risk, neutral, force majeure, weather, variation, late
information, access, utility, statutory, strike, unforeseen conditions), `occurred_on`,
**`notice_required_by`** computed at creation as `occurred_on + contract notice days`, `notice_given_on`
with its document, `particulars_due_by` and `particulars_submitted_on`, `claimed_days`, `awarded_days`,
`cost_claimed`, `cost_awarded`, `status`, the determination fields, a nullable `variation_id` where the
time was granted through one, and `concurrent_with_delay_event_id` — because concurrency is the whole
argument in most extension-of-time disputes. Whether an event is time-barred is **computed** from the
notice dates, never stored.

**The notice clock is the most valuable thing in this section.** A due-date with a notification attached
is worth more commercially than the entire programme: a valid claim lost to a missed notice is the single
most common way a contractor donates money, and it fails in absolute silence. Build `notice_required_by`
and its daily notification before anything else here.

## §14 Earned value, to ANSI/EIA-748

The control account is the intersection of §2 — job, WBS node and cost code — which is where budget,
scope and actuals meet, and it is what makes earned value computable rather than decorative.

`construction_progress_measurements`: `job_id`, `wbs_node_id`, `cost_code_id`, `period_start`, `method`
(`units_completed|incremental_milestone|weighted_steps|percent_complete|level_of_effort`),
`quantity_completed`, `quantity_total`, `percent_complete`, **`earned_value`** — the budget at completion
for that element multiplied by percent complete, computed at measurement time and **frozen**, because the
budget moves when a revision is approved and last month's earned value must not — plus `measured_by`,
`measured_on` and `locked_at`.

Everything else is derived at read: `CV = EV − AC`, `SV = EV − PV`, `CPI = EV / AC`, `SPI = EV / PV`,
`ETC = EAC − AC`, `VAC = BAC − EAC`, and an estimate at completion by whichever of the three standard
methods the forecaster chose — actual plus a manual cost to complete, actual plus remaining budget where
the variance is judged atypical, or budget over CPI where it is judged typical. **The report shows which
method each line used**, because "the forecast went up" and "somebody changed the method" are different
facts and only one of them is news.

**Earned value is measured physically and never derived from cost.** If earned value comes from cost then
it equals actual cost, the cost performance index is exactly 1.00, and every job in the system reads
*precisely on budget* forever. This is the commonest way an earned-value implementation becomes
decorative, and it is a silent failure of the purest kind — every number is present and none of them
means anything.

Two measurements coexist and they are not the same thing, which is worth stating because someone will
otherwise try to merge them: a **claim line** measures a *contract item* and produces revenue; a
**progress measurement** measures a *control account* and produces earned value against budget. They are
reconciled through the WBS node they share, never by forcing one to be the other. A remeasured contract
where the two disagree is not a bug — it is the margin.

**An unphased budget cannot produce a schedule variance.** The report must say *"schedule performance
unavailable: the budget is not time-phased"* rather than showing zero. A zero that means "no data" is the
archetypal silent failure in this whole family of metrics.

## §15 Document control, to ISO 19650

ISO 19650 asks for four things: every information container has a unique identifier and a standard name;
every container carries a **suitability status** saying what it may be used for; revisions are controlled
so a superseded one is not in use; and containers move through **work in progress → shared → published →
archived** with an approval gate between the states.

`construction_documents` is the register: `job_id`, `information_container_id` (the assembled name,
unique per job), then the naming fields **each as its own column** so the identifier can be both
re-assembled and validated — `project_code`, `originator_code`, `functional_code` (volume or system),
`spatial_code` (level or location), `form_code` (drawing, specification, method statement, calculation,
schedule, model), `discipline_code`, `container_number` — plus `naming_convention_id`, `title`,
`description`, `document_type`, **`cde_state`**, `current_revision_id`, `originator_contact_id`,
`wbs_node_id`, `location_id`, `is_contractual`, `confidentiality`, and `superseded_by_document_id`.

**The naming convention is a per-project record, not a hardcoded list.** ISO 19650 mandates the *fields*;
every project issues its own code lists for what goes in them. Hardcoding the codes makes the module
unusable on job number two.

`construction_document_revisions`: `revision` (`P01`, `C02`, `A`, `3`), **`suitability_code` as a string
against a per-job code list rather than an enum** — S0–S7 and A1–A5 and B1–B5 is the UK ISO 19650-2 list,
while AIA-land issues "for construction", "for approval", "as-built", and an enum here fails on the
second project — `cde_state_at_issue`, `status`, `reason_for_issue`, `issued_on`, `issued_by`,
`received_on`, the file fields, **`file_hash`** (a sha256, which is the only reliable answer to "is this
the same drawing" and the thing that catches a re-issue with no changes), `scale`, `sheet_size`,
`drawn_by`, `checked_by`, and the approval gate — `approval_status`, `approver_id`, `approved_at`,
`approval_comments`.

`construction_transmittals` with their items and **recipients** — each recipient carrying a role of
action, information or approval, and `notified_at`, `acknowledged_at`, `acknowledged_by_name` and
`chased_at`. The acknowledgement record is the point: a transmittal nobody acknowledged is a drawing
somebody will later say they never received.

The state machine is enforced in a service, never in a form. A revision may not move to *shared* while
its approval is pending, and **published is the only state a construction-issue drawing may be in** — an
inspection, an RFI answer or a punch item referencing a work-in-progress drawing is a defect waiting to
be built.

### 15.1 What is realistic without a BIM viewer, stated bluntly

Worth building: the register, the naming fields with validation, revision history, suitability codes, the
four-state machine with its approval gate, transmittals with acknowledgement, in-browser preview of PDFs
and images (a PDF renders natively in an iframe, which is free), file hashing, and an unambiguous current
revision.

Not to be attempted here: IFC parsing, model federation, clash detection, 3D viewing, drawing markup and
redlining, DWG rasterisation, or reading a sheet number off a title block. Each is a product rather than
a feature, and each has a mature commercial answer the customer already owns.

The honest middle: a `model` document type exists so the register is *complete* — the IFC or RVT file is
stored as a container with its metadata and its hash, and people download it into whatever viewer they
have. The application never opens it, and the interface says so rather than showing a broken preview.

### 15.2 Storage, and one thing that is advisory rather than enforced

Use the existing pattern — `FileUpload` on the `public` disk with `TenantStorage`, streamed through
`TenantFileController`, which checks tenant membership and path safety. That disk is deliberately not
web-served: `config/filesystems.php` leaves the symlink mapping empty on purpose, and its comment says
why. Do not invent a second storage path.

Two deltas worth writing down rather than discovering. `TenantFileController` checks **company membership
and nothing else** — there is no per-document policy hook — so a `restricted` confidentiality on a tender
build-up or an incident investigation naming an injured person was **advisory, not enforced**: any user of
the company holding the URL could stream it. **Enforced 2026-10-26**: `App\Support\TenantFileAccess` is the
Core policy hook, Construction answers through `DocumentPolicy::view`, and a restricted document needs
`ConstructionDocumentRestrictedView` (granted beside Publish) on top of View. Either say so on the field's helper text, or scope a Core
change to add a policy resolver — as a Core change, deliberately, not smuggled in behind a construction
migration. And the Lifecycle precedent's 8 MB upload cap is far too small for a drawing set: make it
configuration, defaulting to something like 50 MB, and state plainly that the browser upload path is not
how a two-gigabyte point cloud arrives — those come by other means and are registered here by reference.

## §16 Field operations

### 16.1 The daily site log

`construction_daily_logs`, **unique on `(job_id, log_date)`** — the constraint is the feature, because two
site diaries for one day is how a dispute starts. Weather morning and afternoon, temperature, rainfall,
wind, `working_conditions` (`workable|partially_disrupted|stopped`) and **`weather_hours_lost`** — those
last two, not the free text, are what a weather-based extension of time is actually made of — then work,
delays, instructions received, visitors, and safety, quality and environmental observations, with
`submitted_by` and `approved_by`. **Approval locks the row.** An editable site diary is not evidence.

Its children are where the value is:

- **Manpower** by contact and trade, with headcount, hours and overtime — which feeds dayworks pricing,
  the manpower histogram, and the exposure-hours denominator of §17.
- **Plant**, with hours **working, idle and breakdown** kept apart, because idle against working is what
  a standing-time claim is made of and one combined hours column loses it entirely.
- **Deliveries**, with docket number, received-by, purchase order reference, contract item, and an
  `is_materials_on_site` flag — **the link that makes G703's "materials presently stored" column
  defensible rather than asserted.**
- **Events** — delay, instruction, visitor, stoppage, inspection, incident — each with times, hours lost
  and a responsibility of employer, contractor or neutral, and a nullable link to the delay event of §13.
- **Photos**, in their own table rather than as CDE containers. A site photo has no revision, no
  suitability code and no approval, and forcing thousands of them into the ISO 19650 register creates
  junk containers and buries the drawings the register exists for. There is a *promote to register*
  action for the handful that become as-built evidence.

### 16.2 RFIs

`construction_rfis`: `job_id`, `contract_id`, `rfi_number` (per job, no gaps), `subject`, `question`,
`proposed_solution`, `discipline`, `location_id`, `drawing_document_id` (which drawing it is about),
`specification_reference`, `activity_id` (which programme activity it blocks), `raised_by`, `raised_on`,
`required_by`, **`ball_in_court`** as a role *and* `ball_in_court_contact_id` as a person — both,
deliberately, because the useful report is "seventeen RFIs sitting with the Architect" and the useful
email goes to a named individual — `status`, **`cost_impact_flag`** and **`time_impact_flag`** as
`none|possible|yes` with nullable estimates beside them, the answer fields, and `variation_id` for the
RFI that became a change.

Flags rather than amounts at raise time, because at raise time nobody knows, and forcing a number
produces a column of zeros that later reads as "no impact" when it meant "not yet assessed".

Days open, overdue and response time are all computed.

### 16.3 Submittals

`construction_submittals`: `spec_section` (the MasterFormat section, which is the register's natural key),
`title`, `type` (product data, shop drawing, sample, mock-up, calculation, certificate, test report,
O&M manual, warranty, as-built, method statement, material approval, qualification),
`responsible_contact_id`, then the lead-time fields that are the whole point of the register —
`required_on_site_date`, `fabrication_lead_days`, `procurement_lead_days`, `review_period_days`,
`buffer_days` — plus the actual dates, `status`, `revision`, `document_id`, `activity_id` and
`is_long_lead`.

**The submit-by date is computed** by working backwards from the required-on-site date. Typed, it goes
stale the day the programme moves, and a stale submit-by date is worse than none.

`construction_submittal_reviews` is a **row per round**, with reviewer, dates, result, comments and
turnaround. A submittal that has been round three times is a schedule risk, and a single status column
loses that fact completely.

### 16.4 Punch and snag lists

`construction_punch_lists` (pre-handover, handover, defects, client, internal) and
`construction_punch_items` with reference, description, trade, location, drawing, grid reference, dates,
priority, responsible contact, before and after photographs, `cost_to_rectify`, a `back_charge_id`, and
**`affects_practical_completion`** — the flag §11's AIA holdback reads.

`construction_punch_inspections` records each re-inspection attempt with its result. "Closed after three
failed re-inspections" is a different fact from "closed first time", and a single closed-at column loses
it — the same reasoning `tickets.reopened_count` already gives in its own migration: counted rather than
inferred from status history.

### 16.5 Locations — one small tree, shared by five subsystems

`construction_locations`: `job_id`, `parent_id`, `code`, `name`, `type`
(`site|building|block|level|zone|room|grid|chainage|structure`), `sort`.

Build it. Punch items, diary photos, inspections, NCRs and incidents all need to say *where*. Five
free-text location columns is five spellings of "Level 3 East", and the report that matters most before
handover — *every open item in this room* — becomes impossible to write.

## §17 Quality, health, safety and environment

### 17.1 Inspection and test plans, to ISO 9001

`construction_itps` is the plan; `construction_itp_activities` are its rows: `sequence`,
`activity_description`, `reference_standard`, `acceptance_criteria`, `inspection_method`, `frequency`,
`record_form`, **`point_type`** (`hold|witness|review|surveillance|monitor`) and `notice_hours`.

That distinction is the entire reason an ITP exists: a **hold** point means work may not proceed past it;
a **witness** point means a party is invited and work may proceed if they do not attend; a **review**
point is documentation only. Collapsing them into a checkbox turns the document into a formality.

`construction_itp_activity_parties` is a pivot rather than a single column, because "who must attend" is
exactly the question a hold point answers, and one column cannot say *the Engineer witnesses, a
third-party laboratory verifies, the Employer approves*.

`construction_inspections` is the record: the ITP row it came from (nullable — ad-hoc inspections exist),
reference, location, activity, contract item, the `point_type` **snapshotted at request time**, the
request, notice, scheduled and inspected dates, `status`, who inspected and who witnessed and
`witness_attended`, `result_notes`, a nullable `ncr_id`, and **`released_hold_point`** with who released
it and when. The whole function of a hold point is that work may not proceed past it; a hold point that
releases nothing and blocks nothing is a checkbox with extra steps.
`construction_inspection_checks` is the check sheet — expected value, actual value, unit, pass or fail.

### 17.2 Non-conformance, CAPA and close-out

`construction_ncrs`: `ncr_number`, `raised_by`, `raised_on`, `severity` (`minor|major|critical`),
`category`, `source`, `inspection_id`, **`itp_activity_id`** — the traceability back to the ITP row that
the standard actually asks for — location, contract item, WBS node, activity, `responsible_contact_id`,
`description`, `requirement_breached`, **`disposition`**
(`rework|repair|use_as_is|reject_and_replace|concession_requested`) with a `concession_reference`, which
is ISO 9001's control of nonconforming output and is the field that decides whether money changes hands.

Then CAPA, deliberately as separate fields: `root_cause` with its `root_cause_method`,
`corrective_action` with owner and due and done dates, and `preventive_action` with its own pair.
ISO 9001:2015 dropped preventive action as a clause and every construction client's quality manual still
demands both; merging them produces NCRs whose "preventive action" restates the fix.

Close-out points at the **re-inspection** — `verified_by`, `verified_on`, `verification_inspection_id` —
which is what makes a closure evidence rather than an assertion.

Then the money: `cost_impact`, `deduct_from_payment`, `deduction_amount`, `deduction_certificate_id`,
`back_charge_id`.

**An NCR never deducts automatically.** It *proposes*; the certification service **offers** the deduction
as a row on the certificate that a human confirms and signs for. FIDIC 14.6 permits the Engineer to
withhold; it does not require it. A deduction appearing on a certificate that nobody decided on is the
fastest available route to a dispute, and it will be the contractor's dispute, because the client's copy
has already left the building. The precedent is recorded in this repository in
`ModuleBoundaryTest`'s own commentary on `final_settlements.payslip_id`: a nullable column recording
which existing path paid a settlement, which the settlement itself never writes — *a proposal, not a
posting*, visible in the import graph.

### 17.3 Incidents, to ISO 45001

`construction_incidents`: `incident_number`, `kind` — and **near miss is a first-class kind rather than a
checkbox**, because near-misses reported per lost-time injury is the leading indicator that predicts the
next one — through unsafe act, unsafe condition, first aid, medical treatment, restricted work, lost-time
injury, fatality, property damage, environmental, fire, security, dangerous occurrence and occupational
illness.

`occurred_at` is a **datetime**, not a date, because shift timing is half the analysis; `reported_at`
sits beside it and the reporting delay is computed and is itself a safety metric. Then location, the
activity being performed, `injured_person_type`, a nullable `employee_id` guarded on Employees, and
`injured_person_name` as **free text that must work on its own** — a subcontractor's labourer is not in
this system, and pretending otherwise loses the record entirely. Then `is_lost_time`, `days_lost`,
`restricted_days`, treatment, body part, injury type, agency, the cause and investigation fields, and the
authority-reporting fields, with witnesses and photographs as children.

### 17.4 One actions table for all of it

`construction_actions` is polymorphic over NCRs, incidents, inspections, toolbox talks and audit
findings: description, action type, assignee as user or contact or plain name, due date, priority,
status, completion and verification.

Every QHSE object generates the same record — somebody must do something by a date and somebody else must
verify it. Four separate action tables produce four "overdue actions" reports that never agree, and the
safety manager's one genuinely useful screen — everything overdue, from every source, in one list —
becomes a four-way union nobody maintains.

### 17.5 Permits, toolbox talks and the induction register

`construction_permits`: `permit_number`, `type` (hot work, confined space, working at height, excavation,
electrical isolation, lifting operation, road closure, live services, demolition, radiography, night
work, diving, pressure testing), description, location, activity, **`valid_from` and `valid_to` as
datetimes** — a permit is time-boxed, and an expired-but-open permit is the failure mode that kills
people — requester, number of persons, a `details` JSON for the genuinely type-specific fields (gas
readings, isolation certificate reference, rescue plan, wind limits) with the shared fields staying real
columns so they can be queried, the issue and acceptance stamps, `status`, suspension fields, and
close-out with `area_made_safe`. **An extension is a new row** pointing back at the one it extends;
overwriting `valid_to` destroys the record of what was authorised when.

`construction_toolbox_talks` with attendees, and `construction_site_personnel` as the induction and
competency register with `construction_competencies` beneath it — training, licences, certifications,
medicals, authorisations, each with an expiry and the same `expiry_notified_at_days` transition pattern.
In both tables a person's **name must work without an employee record**, because most attendees on most
sites are a subcontractor's labourers.

### 17.6 Indicators, and the denominator nobody has

Lagging indicators are computed on a report page and never stored: lost-time injury frequency rate,
total recordable incident rate, severity rate, accident frequency rate, and the near-miss ratio that
crosses into leading. **The rate base must be printed on the face of the report** — a frequency rate
without its base is a number that gets compared against a competitor's figure computed on a different
one, and 1,000,000 against 200,000 is a factor of five with both called "the standard".

Leading indicators: near-miss reports per period, toolbox talks delivered and attended, inspections
completed against planned, permits closed on time, overdue actions, induction coverage, and the
proportion of hold points released at the first attempt.

**Exposure hours are the denominator and nobody has them.** The source is the sum of daily-log manpower
hours, which means the safety indicators are guarded on the field module — and this is a genuine silent
failure rather than a graceful degradation: with no diary the denominator is zero and the frequency rate
renders as `0.00`, which reads as a perfect safety record and actually means nobody filled anything in.
The page must say **"insufficient exposure data"** and refuse to render a rate. The mirror-image failure
is double counting the same people from the diary *and* from Timesheets, which halves every rate; the job
names one source and the report prints which one it used.


## §18 The module boundary

Five registry keys, because a module here is a commercial boundary and not a folder. A contractor buying
site management, document control and safety while keeping its books in another system is a real and
common customer; so is one buying nothing but job costing. Selling this as one module quotes every
prospect for the whole thing.

```
construction             jobs and sites, locations, the WBS, the cost-code library, the
                         ISO 19650 document register and transmittals   requires: nothing

construction_costing     the job-cost ledger, budgets and forecasts, EVM, procurement and
                         commitments, labour, plant, materials, reconciliation and WIP
                                                        requires: construction, accounting

construction_contracts   the head contract and its BoQ/SOV, variations, progress claims,
                         certificates, the retention ledger, subcontracts and compliance
                                                                   requires: construction

construction_field       the site diary, RFIs, submittals, punch lists, the programme and
                         delay events                              requires: construction

construction_qhse        ITPs and inspections, NCRs and CAPA, incidents, permits to work,
                         toolbox talks, induction and competency   requires: construction
```

**`construction` requires nothing, and that is this plan's one architectural decision.** The precedent is
stated outright in the registry for CRM — *"requires NOTHING, and that is the plan's one architectural
decision… CRM owns its own `leads` table and must be sellable to a company that has bought neither
Invoicing nor Accounting"* — and the argument here is stronger, because it is what the self-contained
decision in §1 actually means. Requiring Accounting on the spine would undo it in the registry.

**`construction_costing` requires `accounting`, genuinely.** The whole of §4 is reconciliation to the
books, cost codes map to `accounts`, WIP is a journal entry and the period close posts. Job costing with
no books is a spreadsheet, and this design would be almost entirely dead code. The precedent is
`personal_finance`, which requires Accounting for a weaker reason and whose plan records that removing
the requirement was the wrong shape.

**`construction_contracts` does not require `invoicing`, and this is the sharpest fork in the section.**
The tempting precedent points the other way: `quotations` requires Invoicing because *"a quote whose whole
point is becoming an invoice, and which can never convert, is a PDF generator"*. Copying that reflexively
would be wrong, because **a payment certificate is not a quote**. A certificate is itself a contractual
instrument: it starts the payment period, the client's surveyor countersigns it, an adjudicator reads it,
and under FIDIC its issue is an obligation of the Engineer whether or not anybody raises a tax invoice.
Large contractors run certification in the commercial department and invoicing in finance, frequently on
different systems. So without Invoicing the *Raise invoice* action is absent, `certificates.invoice_id`
stays null, and the certificate register — with its retention ledger, its variation history and its
printed forms — is still the whole deliverable. The honest cost: retention held has no ledger account
until Invoicing is licensed, so the register is a contractual record rather than a financial one, which
is acceptable because retention held is a fact about the contract regardless of where the books are kept.

**The document register lives in the spine, not in the field module.** RFIs reference drawings;
submittals *are* documents; transmittals carry contract notices; ITPs reference approved-for-construction
drawings; compliance certificates and variation instructions are containers. In the field module, both
Contracts and QHSE would need it as a coupling — and worse, a customer who bought contracts and quality
but not site operations would have no register at all, which makes both of those half-useless.

### 18.1 Guarded rather than required

| Module | What it adds | Absent |
|---|---|---|
| `invoicing` | the certificate's *raise invoice* action, the supplier-bill allocation queue | certificates stop at certification; `invoice_id` stays null |
| `inventory` | site stores, stock-tracked issues, FIFO issue cost | direct-to-site costing only; issue lines carry a typed rate |
| `employees` | manager, surveyor and site agent; employee labour | `construction_workers` carries everyone and `created_by` answers ownership — exactly `crm -> employees` |
| `timesheets` | importing timesheet entries as labour records | site sheets only, which is the primary path anyway |
| `payroll` | absorbing burden against actual payroll cost | burden is charged and never absorbed, and §4 reports the gap in words rather than balancing |
| `projects` | `construction_jobs.project_id` | the field is absent and the column stays empty — exactly `invoicing -> projects` |
| `construction_field` | dayworks pricing, the AIA punch-list holdback, the safety exposure denominator | the dayworks action is absent, the holdback is zero **with the reason on the movement**, and the safety report says "insufficient exposure data" rather than rendering a rate |
| `construction_qhse` | the NCR deduction picker on a certificate | the picker is absent, and an NCR never deducts on its own anyway |

Each absent makes the module smaller and never broken, which is what `ModuleDegradationTest` exists to
assert. The two exceptions to "smaller, never broken" are named above rather than left implicit: a
zero holdback and a zero exposure denominator both *look* like healthy numbers, so both say so in words.

### 18.2 The rest of the wiring

`docs/new-module-checklist.md` is the authority and this plan restates none of it. What is specific here:

- **Directory casing: `ConstructionQhse`, never `ConstructionQHSE`.** `ModuleMap::moduleFor()` matches
  `App\Modules\([A-Za-z0-9]+)\` and runs `Str::snake()` on it, and `Str::snake('ConstructionQHSE')` is
  `construction_q_h_s_e`, which is not a registry key — so `moduleFor()` returns null. The checklist's own
  table then says what happens: a resource using the trait throws, which is loud and fine, but **a class
  with its own `canAccess()` is silently ungated, and so is `Gate::before`**. `ModuleBoundaryTest` uses
  the same regex, so the boundary lint would stop seeing the module too. Five modules, one capitalisation
  rule, decided once.
- **A `construction` company profile** in `config/company_profiles.php` — *Construction / Contracting*,
  business type, licensing `accounting, invoicing, inventory, employees, payroll, attendance, leave,
  lifecycle, crm, quotations` and all five construction keys, closed under `requires`. Without one,
  `CompanyProfileTest` fails the build, which is the point. Worth noting and **not** building yet: the
  Engineer or Architect practice that *certifies* rather than claims is already accommodated by the
  `side` enum — their fee agreement is receivable and they administer the contractor's contract as a
  third party — and is a second profile later, not a second schema.
- **A separate `ConstructionAccountsSeeder`**, not an extension of `ChartOfAccountsSeeder`. Adding a
  dozen construction accounts to every bookkeeping company's chart is noise, and the profile mechanism
  exists for exactly this. New codes: materials on site, contract assets, retention receivable, goods
  received not invoiced, accrued subcontract costs, retention payable, contract liabilities, provision
  for foreseeable losses, contract revenue, job cost by type, and the three credit-normal recovery
  accounts — plant internal hire recovery, labour burden absorbed, over/under absorption.
- **A `ConstructionAccounts` support class** copied in shape from
  `app/Modules/Accounting/Support/PayrollAccounts.php`, including its error messages, which name the
  settings page *and* the seeder because "account 5100 cannot accept entries" names neither the caller
  nor the fix.
- **Reference data is a licensing question before it is an engineering one.** MasterFormat is CSI's,
  Uniclass is NBS's, NRM is RICS's, ICMS is the ICMS Coalition's. Ship the **structure** — a tree the
  customer populates — plus a CSV import, and seed only what is genuinely publishable: the fifty
  MasterFormat division titles, an elemental UniFormat set, and the ICMS Level 2/3 spine. **Confirm the
  redistribution terms before any code list ships**, alongside the questions
  `docs/fbr-digital-invoicing-plan.md` already parks for an advisor. Ten thousand seeded rows per tenant
  is also a table nobody will ever prune.
- **`ModuleMap`** gains roughly sixty models across the five keys, every resource, page and widget, and
  the permission groups. Morph aliases take the legacy `App\Models\{Basename}` form —
  `'App\Models\ConstructionCostEntry' => …` — which `ModuleCoverageTest` asserts unconditionally with no
  exemption for classes that never lived there; the checklist §4 records this being hit for real. And
  every **plain-column** morph write goes through `ModuleMap::alias()` in a mutator, because
  `enforceMorphMap()` does not cover those: `certificate_deductions.source_type`,
  `construction_actions.actionable_type`, `variations.source_type`, `back_charges.source_type`,
  `cost_entries.source_type` and anything this suite writes into `journal_entries.source_type`.
- **Permission groups** named once and forever, because the `permissions` table has no unique index and
  the seeder matches on name *and* group — so regrouping one later creates a second row with the same
  name while existing roles keep pointing at the first, and nothing reports it. Regrouping after release
  is a data migration, not a seeder edit. Small tables ride on their parent's group, following the Leave
  precedent that eight more permission names for two tables nobody navigates to is eight more rows in
  every role form for no decision anybody makes separately. The non-CRUD names are the ones that matter,
  and each is a separate decision made by a separate person: `ConstructionCertificateCertify`,
  `ConstructionCertificateInvoice`, `ConstructionVariationPrice`, `ConstructionVariationApprove`,
  `ConstructionRetentionRelease`, `ConstructionComplianceOverride`, `ConstructionNcrClose`,
  `ConstructionInspectionRelease`, `ConstructionPermitIssue`, `ConstructionDocumentPublish`,
  `ConstructionDailyLogApprove`, `ConstructionPeriodForceClose`.
- **Navigation, and it is bigger than it looks.** `NavigationDomains::DOMAINS` has six domains and every
  group must be claimed by one or it is unreachable. Construction gets a **seventh domain** rather than a
  fold into Finance: this suite is more resources than Finance and Sales combined, and folding it in
  reproduces exactly the flat-thirteen-groups problem that class was written to solve — quite apart from
  making the module read as an accounting add-on. Groups `Construction`, `Contracts`, `Site`, `QHSE`;
  three of the four exceed `NavigationTree`'s own six-entry threshold for "a scroll rather than a menu",
  so `Contracts` branches into head contract and subcontracts, `Site` into daily, queries and close-out,
  and `QHSE` into quality and safety. `Construction` stays flat at four entries, because a branch holding
  two things is a click in front of a list rather than a shorter one. **The map must stay exhaustive**:
  an item in a mapped group that no branch claims keeps its original label, which no domain then owns,
  and it disappears from the menu — `NavigationTreeTest` is the only thing that sees it.
- **Scheduled work** in each module's `routes/console.php`, licence-guarded inside the command with model
  classes as class constants: `construction:accrue`, `construction:reverse-accruals`,
  `construction:reconcile`, `construction:close-period`, `construction:rebuild-paths`, plus the
  notification runs — compliance and competency expiry, delay-event notice due dates, retention becoming
  releasable, and the retention register reconciliation of §11.
- **`ModuleBoundaryTest::KNOWN_COUPLINGS`** gains one entry per module, and `construction_*` →
  `construction` is deliberately **not** among them: it is a declared `requires` and `allowedTargets()`
  recurses into requirements, so listing it is noise that the stale half of the test would then have to
  tolerate. Note the trap rather than discovering it —
  `test_the_recorded_debt_does_not_hide_a_licence_dependency` asserts the **exact array in exact order**
  for each module in its own guarded list, so the two cross-sibling money paths must be kept in step in
  two places.

  > **Resolved 2026-08-19, in Phase 6c: there is exactly one cross-sibling money path, and there can only
  > ever be one.** `construction_contracts -> construction_costing` — the subcontract certificate relieving
  > its commitment — is in both lists. The path back was the tempting second one (a contract picker on the
  > order form, so the link could be made where the order is raised), and it cannot exist: two modules naming
  > each other is a cycle, and a cycle cannot be expressed as a composer dependency, so neither sibling would
  > ever be extractable. The certificate is what triggers the relief, so contracts is the side that reaches,
  > and the linking screen follows the coupling rather than the other way round.

  Three entries must **not** be added, and each refusal is a design constraint worth keeping:
  no `'invoicing' => [… 'construction']`, which is what forces the allocation queue of §5 to exist as its
  own screen rather than as a job picker on the invoice form; no `'core' => [… 'construction']`, because
  the account map belongs on a Construction settings page and `core -> accounting` already exists for
  having made that mistake once; and no `'accounting' => [… 'construction']`, because a job-cost summary
  widget registers in its own plugin where it belongs.

### 18.3 Scale, and the one materialised total

A five-year job is five hundred cost codes, sixty periods and a hundred thousand cost entries. The
four-column report is four grouped queries, never an accessor per row — and the trap is worth naming
because it will otherwise be written by accident: a `getStateUsing()` that sums entries per row is five
hundred queries on a five-hundred-row report. `docs/retail-stores-pos-plan.md` names the same trap and
the sharpest part of that note applies here — **the resources smoke test creates only a user, so every
table it renders is empty and it waved two 500s through.** The job cost report needs a query-budget test
that renders **with rows**, written before the page.

Both trees carry a materialised `path`, so a subtree filter is one index range scan rather than the
`childrenRecursive` pattern `Account` uses, which issues a query per depth level — fine for a two-level
chart of accounts, not fine for a six-level WBS rendered per row.

The one exception to computed-not-stored: `construction_period_cost_summaries`, written once by the
period close for a period that by §3.4's own rules can never change again. Drift is impossible by
construction rather than by discipline — and the test that makes that a claim rather than a hope picks a
random closed period, recomputes it from the entries and asserts equality.

## Phases

Each phase is shippable and leaves the application working. The order is not negotiable in three places:
Phase 0's Invoicing fix before any payable path, the ledger before anything that reports on it, and the
document register before the modules that reference drawings.

- **Phase 0 — Decide, fix, then measure.** The decisions that cannot be reversed cheaply, all of which
  now have to be made before code. The `stock_locations` fork with `docs/retail-stores-pos-plan.md` (§6),
  which is a one-way door and belongs in *that* plan's Phase 0 as much as this one's. The classification
  redistribution licensing question (§18.2). Construction as a seventh navigation domain. The directory
  casing rule. And the one genuine prerequisite in another module: **the negative-line sign flip in
  `InvoiceService::purchaseEntryLines()`, with a test** (§10.4) — a three-line change that is latent
  today and silently drops the retention line the moment §12 exists. Then a query-budget test for the job
  cost report before the page exists. **Ends with:** the answers written back into this document, and a
  failing-then-passing test in Invoicing for a bug nothing currently triggers.

  > **Started 2026-08-17. The Invoicing fix is done** — `purchaseEntryLines()` now mirrors
  > `saleEntryLines()`'s leg flip, covered by `PurchaseInvoiceNegativeLineTest` on both the base and
  > foreign-currency paths. It corrected the risk entry: the failure was a hard refusal, not a silent
  > absorption. See the Risks section.
  >
  > **All four decisions are now made (2026-08-17).** Recorded here because Phase 0's whole deliverable is
  > the answers written back into this document.
  >
  > **1. Casing: `App\Modules\ConstructionQhse`.** Verified rather than reasoned —
  > `Str::snake('ConstructionQHSE')` returns `construction_q_h_s_e`, which matches no registry key, so
  > `moduleFor()` returns null and every class with its own `canAccess()` is silently ungated.
  >
  > **2. Stock location: `stock_locations`, owned by Inventory.** Adopting §6's recommendation, and the
  > decision is *free right now* — neither this plan nor the retail one has been built, `stock_movements` has
  > no location column of any kind, and no backfill has happened, so the one-way door is still open. It will
  > not stay open: whichever plan starts first walks through it. `stock_movements.stock_location_id`,
  > backfilled once; `stores.stock_location_id` in retail and `construction_jobs.stock_location_id` here;
  > neither module depends on the other. The alternative — `store_id → stores` — makes a building site either
  > a fake store row carrying till settings and a POS registration number, or a second nullable dimension,
  > which is the on-hand-wrong-per-location-right-in-total failure §6 names. **Written into
  > `docs/retail-stores-pos-plan.md` §0.1 as well**, because that is where the migration belongs and because
  > two plans each assuming the other will raise it is how neither does. The `stock_movements.type` enum
  > expands once, in the same migration, for both plans: `issue`, `return`, `transfer`, `waste`,
  > `count_adjustment`.
  >
  > **3. Reference data: ship the structure, seed nothing proprietary.** The licensing question is *not*
  > answered here and must not be — MasterFormat is CSI's, Uniclass NBS's, NRM RICS's, ICMS the Coalition's,
  > and nobody on this side of the code can clear redistribution terms. What is decided is the shape that
  > makes the question **non-blocking**: Phase 1 ships the cost-code tree, the ICMS *mapping* columns and a
  > CSV import, and **zero seeded code lists**. A customer who owns a licence loads their own file. Seed
  > packs per standard become a later, separate deliverable, each gated on written terms for that standard —
  > so the advisor question runs in parallel with Phase 1 instead of in front of it. This also disposes of
  > the ten-thousand-rows-per-tenant objection by not creating them.
  >
  > **4. Navigation: a seventh domain.** Adopting §18.2, with the claim checked: Finance today is **24
  > classes across three groups** (Accounting 14, Invoicing & Inventory 7, Audit & Taxes 3) and Sales is 8.
  > Construction's four groups — three of which need branching — would push Finance past fifty classes and
  > seven groups, which is the flat-many-groups problem `NavigationDomains` was written to solve. The cost is
  > bounded and worth stating: `rail()` omits a domain whose groups are all empty, so a company that never
  > buys construction still sees six icons and nothing changes for it.
  >
  > The query-budget test waits on Phase 2, since there is no report to budget yet.
- **Phase 1 — The spine.** `construction` module wiring end to end — registry, profile, plugin, provider,
  `ModuleMap`, policies, permissions, the navigation domain and its branches — then jobs, locations, the
  WBS, the cost-code library with its ICMS mapping, and the ISO 19650 document register with revisions
  and transmittals. No costing, no contracts. **Ends with:** all eight `Module*` tests green before a
  single domain table carries data, and a surveyor can build a job, a work breakdown and a coded bill.
- **Phase 2 — The cost ledger.** `construction_costing`: cost entries, batches, periods, manual entry,
  reversal, and the cost report computed live. **Ends with:** cost can be recorded and reported by hand,
  and the invariant of §3.2 holds under a test that reverses and re-books.
- **Phase 3 — Budget, forecast and earned value.** Budget versions and the baseline, forecast runs,
  progress measurements, EVM, the four-column report. **Ends with:** the report that sells the module,
  and a schedule variance that says "unavailable" rather than zero on an unphased budget.
- **Phase 4 — Contracts, receivable side.** `construction_contracts`: contracts and items, variations,
  claims, certificates, deductions, the retention ledger, and the printed forms. **Ends with:** the two
  worked examples of §8.4 reproducing identically under both PDF engines — which is the assertion, not
  the illustration.

  > **Built 2026-08-18, except the invoice hand-off** — named in the status block above with the reason it
  > was left. What landed: the contract and its schedule with execution freezing the scheduled values;
  > variations with `agreed()` and `forecast()` and a source-level test asserting no bare
  > `where('status', 'approved')` survives anywhere in the module; claims and certificates as two
  > documents, cumulative, with the previous figures snapshotted rather than joined; every deduction a row
  > with retention, advance recovery and previously-certified computed; the retention ledger with the
  > release rules as one function with a branch, a zero AIA holdback that states its reason in words, and a
  > nightly reconciliation that warns rather than throwing; the printed certificate, chunked in PHP so
  > the document is the same under either PDF engine, with §8.4's two worked examples asserted against
  > their stated figures and 22,360,000 coming out of the FIDIC one; and §10.4's hand-off, which produces
  > that same 22,360,000 as a **draft** invoice of four lines — the work gross to contract revenue,
  > retention to a *receivable asset*, advance recovery against the liability it created, and the NCR
  > deduction back against revenue — with `previously_certified` deliberately not a line, because it is how
  > a cumulative certificate expresses a period figure rather than a deduction to bill.
- **Phase 5 — Procurement.** Requisitions, commitments and their variations, goods receipts, invoice
  allocations, the allocation queue screen, three-way match, relief. **Ends with:** open commitment is
  provable per cost code and closing a purchase order with a balance has an author and a reason.

  > **Built 2026-08-18.** —
  > `ConstructionCommitmentTest` (27 tests — the 27th arrived with Phase 6c, below),
  > `ConstructionRequisitionTest` (24), `ConstructionGoodsReceiptTest` (21),
  > `ConstructionInvoiceAllocationTest` (19) and `ConstructionThreeWayMatchTest` (24) —
  > **Phase 5 complete**, 115 tests. The allocation table and its queue
  > answer what §5 calls the single most likely silent failure in the module; the match is computed with only the
  > acceptance stored, and its tolerances are config overridable by settings. The commitment half is both halves
  > of the exit condition: open commitment is `line.amount − Σ reliefs` over issued orders
  > with every relief naming its cause, and closing writes a `close_out` relief with `closed_by` and a
  > mandatory reason. Three decisions worth carrying forward:
  >
  > - **Approved is not committed.** Only `issued` and `partially_relieved` put money on the four-column
  >   report, because an approved order the supplier has not been sent can still be withdrawn with a phone
  >   call. Two buttons, two permissions.
  > - **§3.5's `committed` column stopped being an em dash.** It was `null` rather than `0.00` for two
  >   phases precisely so that "no procurement module" could never be read as "no orders placed" — and
  >   filling it in was one method on `CostLedger` plus one on `ForecastService`, with no figure restated.
  >   The Phase 3 test that asserted the null now asserts the zero, and says why it changed.
  > - **Ordered-against-received is not a match variance while the order is open.** The first `ThreeWayMatch`
  >   compared them, which made every undelivered order read as a total short delivery and every staged one as a
  >   partial — a report wrong on nearly every line, which is the state that teaches people to ignore it. The
  >   two cases are genuinely indistinguishable from the documents: 36 t against 40 is a short delivery *or* the
  >   first of two loads. What settles it is closing the order, which §5 already makes an act with an author and
  >   a reason, so until then the difference is open commitment — reported once, by the register that owns it,
  >   and shown on the match as a note rather than a variance. What the match judges is invoiced against
  >   received, both in quantity and in value, which is what a supplier's own two documents can settle.
  > - **An invoice for goods that *were* received was eating the unreceived balance.** The unreceived-balance
  >   clamp is a ceiling and cannot tell what a particular invoice covers, so an invoice for the 36 t delivered
  >   was relieving the 4 t that never came, closing the order as though the shortfall had been dealt with. The
  >   intent now lives in `InvoiceAllocationService`, which knows what has been invoiced: relief is the excess of
  >   cumulative invoiced over cumulative received. Withdrawing an allocation gives back **what it actually
  >   relieved**, which is not always what it was for.
  > - **A negative invoice relief could not be recorded at all.** The unreceived-balance clamp that implements
  >   §5's double-relief rule ran on every invoice relief, so withdrawing an allocation — `min(-10m, 0)` then
  >   `<= 0 → return null` — silently gave back no commitment, leaving an order relieved for money nobody was
  >   being charged. The clamp now applies in the forward direction only. Same shape as the requisition status
  >   that could not reverse: a rule written for one direction quietly blocking the other.
  > - **The site-store path is refused rather than half-built.** A goods receipt does the two things it can —
  >   relieves the order, accrues the cost at order rate — and refuses a line destined for a store with a
  >   message naming the missing `stock_locations` and telling the user to receive it direct instead. Accepting
  >   it would cost the material as though it had been stocked, and materials-on-site would be wrong with
  >   nothing saying so, which is §18.1's rule about a healthy figure hiding an absence.
  > - **A requisition's `ordered` status has to be able to reverse.** `refreshOrderedStatus()` first treated it as
  >   terminal, which meant an order cancelled after the request was fully ordered could never put the request back
  >   on the buyer's queue — the exact failure the demand document exists to prevent, a need nobody is chasing with
  >   nothing on any screen showing it. Only `cancelled` and `rejected` are terminal; everything else is derived
  >   from the order lines and must be able to move both ways.
  > - **`Commitment::relievedTotal()` had to qualify its column.** It sums a `hasManyThrough` where both
  >   joined tables have an `amount`; SQLite refused the query outright, which was the good outcome. MySQL
  >   would have been entitled to pick either, and picking the line's amount would have made every order
  >   read as fully relieved the moment anything was received against it.
- **Phase 6 — The payable side.** Subcontracts and their terms, subcontract certificates, compliance
  documents with certification blocking, back-charges. Gated on Phase 0's Invoicing fix. **Ends with:**
  a certificate that refuses to certify against expired insurance, and an override that records who and
  why.

  > **6a built 2026-08-18 — the exit condition is met.** — `ConstructionComplianceTest` (30 tests).
  > `construction_compliance_documents` and `construction_compliance_requirements`, `ComplianceService`, the
  > block inside `CertificationService::issue()`, the override trio on the certificate, both registers, the
  > `construction:check-compliance` warning and its notification. §12's two shapes both hold: a certificate
  > against a lapsed general liability policy refuses with the document named and the lapse counted in days,
  > and the override records the user, the time and a mandatory sentence against **that** certificate only.
  >
  > Five decisions worth carrying forward:
  >
  > - **There is no status column, anywhere.** Not in the migration, not on the model, not on the register's
  >   rows. Every status is derived from the dates, against the date being asked about. §12 named the stored
  >   one as the most dangerous silent failure on the payable side and the answer was to make it unstorable.
  > - **Judged on the valuation date, never on today.** A June certificate issued in August is tested against
  >   June's cover. Asking about today would refuse a payment for work that was properly covered when it was
  >   done — a refusal nobody can act on, because the past cannot be re-insured.
  > - **Requirements are a template plus per-contract overrides**, merged with the contract winning. This is
  >   where the one real bug of the phase lived: `Eloquent\Collection::merge()` re-keys by primary key and
  >   returns `array_values`, so merging two collections keyed by `kind` threw the keys away. Every requirement
  >   then reported `missing` while its document sat in the table — the exact shape of failure §12 is about,
  >   arrived at from the opposite direction. `->toBase()` before merging, with the reason in the code.
  > - **A document is not compliance.** Unverified, insufficiently covered and out-of-period documents all
  >   exist and all fail. Filing and verifying are the same permission on purpose: split, the register fills
  >   with documents nobody has read, which is what `verified_at` exists to distinguish.
  > - **The warning exists so the refusal never has to happen.** 60/30/14/7/1 days, once per threshold,
  >   mailed to whoever maintains the register rather than whoever certifies — the person who can chase an
  >   insurer is the person who files the certificates, and mailing the approver offers them only the
  >   override. A first warning delivered as a refused certificate on payment-run day is too late.
  >
  > Two grants, deliberately split: **Accountant** files and verifies (`View`, `Update`); **Manager** holds
  > `ConstructionComplianceOverride`, which both certifies past a block and waives a requirement for good.
  >
  > **6b built 2026-08-18.** — `ConstructionBackChargeTest` (38 tests). `construction_back_charges`,
  > `BackChargeService`, the register with its state-machine actions, and the `back_charge` deduction row written
  > through `CertificationService::addDeduction()`.
  >
  > Four decisions worth carrying forward:
  >
  > - **Notice before deduction, and the un-notified list is a query.** §12's sentence — an incurred-but-unnotified
  >   back-charge is money the company will not get and does not yet know it has lost — becomes
  >   `unnotifiedTotal()`, a filter, and the sidebar badge. `apply()` refuses a draft outright and the refusal says
  >   why: deducting without notice is a payment the subcontractor can recover, so the company would have spent the
  >   money twice.
  > - **Nothing applies itself**, following §16.5's rule for NCRs. `offerFor()` returns what a certificate *could*
  >   take and a human applies one. There is a test that recomputes a certificate three times and asserts no
  >   back-charge appeared — written so a later reader does not take the absence for an oversight and wire it in.
  > - **Draft computes, notice freezes**, the same rule the certificate keeps. `recompute()` is a no-op on anything
  >   but a draft, and after notice the only way the figure moves is `agree()`, whose settled amount sits *beside*
  >   the notified total rather than over it: "notified 240,000, settled at 180,000" is the fact final account needs,
  >   and one column loses half of it. Agreeing above the notified figure is refused — that is a new charge and needs
  >   its own notice.
  > - **Un-applying returns the charge to notified, never to draft.** Notice cannot be unserved, and a charge sent
  >   back to draft would be re-notified with a later date, moving it inside a contractual window it had already
  >   left.
  >
  > **What 6b deliberately does not do is credit the job's cost report.** The cost was recorded when the company
  > incurred it and the recovery reaches the books through the certificate's invoice (§10.4). Whether the job should
  > also show the recovery against the code that carried the cost is §4's question, and §4's reconciliation is Phase
  > 11 — writing a cost credit from here as well would post the same money twice with nothing disagreeing. Stated in
  > the service docblock and in the help page rather than left as a silence.
  >
  > `ConstructionBackChargeApply` is separate from `Update` on the certificate's own asymmetry: raising and notifying
  > is the surveyor's administration, deducting is the act that gets adjudicated. `RoleGrantsTest::EXPECTED` moved to
  > 39 / 117 / 145 / 165 across 6a and 6b together.
  >
  > **6c built 2026-08-19 — Phase 6 complete.** — `ConstructionSubcontractCommitmentTest` (26 tests).
  > `CommitmentService::relieveFromCertification()`, the guarded `CertificateCommitmentService` bridge called from
  > `CertificationService::issue()` and `void()`, `Contract::commitments()` and the *Orders and commitment* tab on the
  > contract. **No migration and no new table**: `construction_commitments.contract_id` was put there by Phase 5 for
  > exactly this, and `CommitmentRelief::KIND_CERTIFICATE` and `CommitmentLine::receivedTotal()` were written in Phase
  > 5 already counting a relief that nothing yet wrote.
  >
  > Six decisions worth carrying forward:
  >
  > - **The target is cumulative; the row written is the movement.** The service drives the certificate reliefs on a
  >   contract's orders towards "what do the *live* certificates say has been certified to date", read off the latest
  >   live certificate rather than summed over all of them — summing cumulative documents would double-count every
  >   period. Three cases then need no code of their own: voiding the latest certificate gives commitment back;
  >   voiding an earlier one while a later stands moves nothing; and linking an order to a contract already
  >   half-certified relieves it at once instead of reporting the whole order as promised against finished work.
  > - **Gross of retention, deliberately.** Retention is cash withheld against work already performed, so netting it
  >   off would leave a twentieth of every subcontract permanently committed with nothing able to relieve it, and the
  >   order would never close.
  > - **Relief follows the cost code the schedule names**, because §8.2 calls `cost_code_id` on a contract item the
  >   join to job cost and the committed column is read *per code*. Pro-rata across the order would leave one code
  >   over-committed and another under-committed on the same order with both figures looking healthy. What no code
  >   can be matched for — an uncoded schedule line is ordinary — is spread rather than dropped, **over the lines
  >   that still have room for it rather than over all of them**: one coded item and one uncoded one, both fully
  >   certified, would otherwise push the coded line past its own amount while leaving the other partly open, which
  >   is two wrong figures on an order that is simply finished. And the pennies pro-rata rounding loses go back on
  >   the largest line, so `Σ certificate reliefs = certified to date` holds exactly. Phase 5's word was *provable*,
  >   not about right, and that is only assertable because of those three lines.
  > - **Nothing is capped at the order value.** An order priced below what has been certified against it
  >   over-relieves, and `Commitment::overRelieved()` answers it. Clamping would make a short order read as complete.
  > - **The coupling direction was forced, and it moved a screen.** This is the cross-sibling money path §18.2
  >   anticipated: `construction_contracts -> construction_costing` in `KNOWN_COUPLINGS` *and* in
  >   `test_the_recorded_debt_does_not_hide_a_licence_dependency`'s exact-order array, which is the trap that section
  >   names. Only one direction may exist — two would be a cycle, and a cycle cannot be a composer dependency — and
  >   the certificate is the trigger, so contracts reaches and costing never names it. Hence the link is made on the
  >   contract, and `CommitmentService` receives a cost-code-to-value map and a total rather than a certificate, the
  >   same discipline `App\Support\PayslipSettlement` keeps for payroll.
  > - **It deliberately does not cost the job**, for the same reason 6b does not credit it. Cost reaches the ledger
  >   through the certificate's purchase invoice and its allocation (§10.4, §5); relief says the money is no longer
  >   *promised*, which is a different sentence from saying it has been *spent*. §5's double-relief rule then holds
  >   by construction rather than by a new guard — `receivedTotal()` already counts certificate reliefs, so the
  >   invoice raised off the certificate finds no unreceived balance and relieves nothing. Asserted, because it looks
  >   like an omission.
  >
  > **The one real bug of the sub-phase was in Phase 5's code and had nothing to do with certificates.**
  > `CommitmentService::relieve()` read the order back off `$line->commitment`, and lazy loading is disabled
  > application-wide — so `close()` and `cancel()`, which loop the lines, threw on the **second** one. Every order in
  > `ConstructionCommitmentTest` had a single line, so the first pass was all anything ever exercised; a subcontract
  > order with one line per trade found it immediately. The order is now fetched by key, and
  > `test_closing_an_order_of_several_lines_writes_off_every_one` asserts the whole loop. Worth remembering as a
  > shape: **a loop whose first iteration is the only one under test is a loop with one case covered.**
  >
  > The same shape decided one line in `void()`: it now fetches the contract by key rather than through
  > `$certificate->contract`, because a certificate voided from the register is a row straight out of the table with
  > no relations on it, while every certificate a *test* holds came from `create()` in the same request and can lazy
  > load freely. **A relation read that only ever runs on a model the same request created is a relation read nobody
  > has tested.**
  >
  > No new permissions: linking an order rides on `ConstructionContractUpdate`, because it is a change to the
  > contract record made from the contract's own screen, and the money decisions on the order itself — approve,
  > issue, close — already have their own names in `construction_costing`.
- **Phase 7 — Labour and plant.** Workers, trades, dated rates, labour records, burden and its
  absorption, plant items and logs, internal hire recovery, and the timesheet import behind its guard.

  > **7a built 2026-08-19.** — `ConstructionLabourRateTest` (33 tests). `construction_trades`,
  > `construction_workers` and `construction_labour_rates`; `LabourRateService` with §7.2's ladder and a
  > `ResolvedLabourRate` to carry its answer; the three registers, and `ConstructionAccounts` needed nothing new
  > because §18.2 had already put `burden_absorbed`, `plant_hire_recovery` and `absorption_variance` in `KEYS`.
  >
  > Six decisions worth carrying forward:
  >
  > - **No rate column exists on the trade or the worker, and their absence is the deliverable.** §7.1's phrase is
  >   "`construction_trades` with default codes and rates", and the reading that survives §7.2 is: the default *code*
  >   is a column, the default *rate* is a `construction_labour_rates` row with only `trade_id` set. A test asserts
  >   both columns are absent, because the next person to add one will be trying to be helpful.
  > - **Job beats person in the ladder**, which is the tier order a reader assumes backwards. §7.2 puts job above
  >   worker deliberately — a site allowance applies to everybody on that site, including the people who carry their
  >   own rate elsewhere — so the test asserts the same man costs 900 on the tower and 800 on the annexe.
  > - **The three figures resolve independently down the ladder.** `overtime_multiplier` and `burden_percent` are
  >   nullable so a job row can revise the rate without restating terms the company set once. A single
  >   "first matching row wins" would give that job an overtime multiplier of nothing and price every overtime hour at
  >   plain time — quietly, and in the company's favour, which is the direction nobody queries.
  > - **Nothing invents a cost rate.** `resolve()` returns null with no row, and `config/construction.php` ships an
  >   overtime multiplier and a burden percentage but deliberately **no** cost rate: policy can have a default, a wage
  >   cannot. Burden ships at zero for a reason of its own — §7.3 requires whatever is charged to be absorbed, so a
  >   shipped guess would start that divergence on day one for a company that never chose it.
  > - **Two rates for the same scope may not overlap, enforced in the service.** The index cannot do it: every scope
  >   column is nullable and nulls are distinct in a unique index on both MySQL and SQLite, so the database would
  >   accept two open-ended company defaults and the resolver would quietly pick one. Same judgement as Phase 3's one
  >   measurement per control account per period. `revise()` exists as one operation because a wage revision *is* one
  >   act, and doing it in two steps is how the two rows come to overlap.
  > - **An unrecognised scope key is refused rather than ignored.** `['worker' => $id]` instead of `['worker_id' => …]`
  >   would otherwise set a company-wide rate applying to everybody, which is a rate nobody meant and nothing reports.
  >
  > **The one real bug of the sub-phase was a repeat, and the repeat is the finding.** A `date` cast serialises through
  > the model's *datetime* format, so `effective_from` is stored as `2026-04-01 00:00:00` — and
  > `'2026-04-01 00:00:00' <= '2026-04-01'` is false as a string comparison. Every rate therefore failed to apply on
  > the exact day it came into force, which is the day a wage revision is always dated to. `whereDate()` throughout
  > (`scopeInForceOn`, the overlap check, `revise()`).
  >
  > **Phase 2 had already found this twice and written it down.** `CostPeriod::scopeStarting()` carries the whole
  > explanation — it "bit four times in one sitting", inserted duplicate periods, and made an entry in a signed-off
  > month report itself editable — and its docblock ends *"anything comparing a date to this column goes through
  > here"*. `CostEntry::scopeInPeriod()` and `period()` carry the same note. None of that helped, because **a warning
  > attached to one column does not travel to the next one**: `construction_labour_rates` was a new table with new
  > dated columns and the same mistake was available again. A grep across the suite for unfixed instances finds none
  > now, and §13's activity dates and §16's diary are the next two tables that will offer it.
  >
  > Two notes rather than decisions. `App\Support\EmployeeOptions` gained `labelFor()`: an employee picker on a plain
  > `employee_id` column cannot use `->relationship()`, which would need an `employee()` method on the model and would
  > put `construction_costing -> employees` in the import graph against §18.1 — the helper is already the sanctioned
  > place for that reach (`ModuleBoundaryTest::SHARED_DEBT`). And **the `Construction` navigation group now holds
  > thirteen entries**, so §18.2's plan to branch it is no longer a prediction; it is overdue, and the group is a
  > scroll today.
  >
  > `RoleGrantsTest::EXPECTED` moved to 40 / 119 / 148 / 168.
  >
  > **7b built 2026-08-19.** — `ConstructionLabourRecordTest` (37 tests). `construction_labour_records`,
  > `LabourRecordService`, the site-sheet register with its approval queue and its reversal action, and the two cost
  > entries §7.3 asks for. No new account keys and no `gl_account_id` written — Phase 5's allocation service sets
  > neither, and §4.1 puts account resolution in §11's posting service where the summary journal is built.
  >
  > Six decisions worth carrying forward:
  >
  > - **Approval is the snapshot, and the two are one act.** The rate is resolved as at the **day worked** — a sheet for
  >   August approved in September is costed at August's rate — and `cost_rate_per_hour`, `overtime_multiplier` and
  >   `burden_percent` are frozen onto the record with `labour_rate_id` naming the row they came from. Two tests hold
  >   the line from both directions: a later revision does not restate booked cost, and *editing the rate row the
  >   snapshot came from* does not either. The second is the one the snapshot alone defends; the dated table cannot.
  > - **Two entries, and the burden one is `pending`.** Labour and burden against the same job, node and code, the
  >   second flagged `is_burden`, so labour cost is answerable on its own from one sum and a filter. With no burden set
  >   anywhere, **no burden entry is written at all** rather than one for zero — a row of 0.00 on every sheet is noise
  >   that makes the register unreadable and the flag useless.
  > - **Who owes the GL the labour posting is decided per person, in one method.** An employee's time already reaches
  >   the books through the payslip, so job cost is a dimension of it and mirrors — posting again would double the
  >   company's labour cost. Anybody paid outside the payroll has no GL document behind them, so it is `pending` and
  >   §11 posts it. Guarded on Payroll being licensed, because "the payslip posted it" is only true where there are
  >   payslips. The figures will not match — rate × hours is not a payslip — and §18.1 already says that gap is
  >   reported in words rather than balanced.
  > - **The duplicate-sheet guard is a ceiling, not a uniqueness rule.** Two records for one worker on one day are
  >   ordinary — morning on formwork, afternoon on steel — so a unique index would refuse the normal case and catch
  >   nothing. What is never ordinary is a day of more than twenty-four hours, which is what the same sheet entered
  >   twice looks like. It applies to an edit as well as to a new sheet, or eighty hours typed for eight would be
  >   refused on creation and accepted on the next save.
  > - **Engagement is checked from the dates, not the flag.** A sheet for March is entered in April, and `is_active`
  >   only ever answers about today. A day booked outside somebody's engagement is usually the wrong person.
  > - **A refusal, never a zero.** With no rate in the ladder, approval is refused and books nothing; the form's
  >   preview says so in words rather than showing a cost of zero, because zero is what somebody would then approve.
  >   The quantity on the labour entry is **hours**, so the unit rate reads as cost-per-hour — minutes would report a
  >   rate nobody prices anything in, which would make §3.1's rate analysis useless on the largest cost on the job.
  >
  > No new permission for reversing: `ConstructionCostReverse` already governs backing a posted entry out of the
  > ledger, which is exactly what this does twice. `RoleGrantsTest::EXPECTED` moved to 41 / 120 / 150 / 170.
  >
  > **7c built 2026-08-19.** — `ConstructionPlantTest` (37 tests). `construction_plant_items` and
  > `construction_plant_logs`, `PlantService`, `PlantHireMatch`, and the two registers with the *Check against
  > invoices* action.
  >
  > Six decisions worth carrying forward:
  >
  > - **Ownership decides whether a log books cost, and that is the whole design.** Owned plant charges the job
  >   internal hire as a `pending` entry for §11 to credit to Plant Internal Hire Recovery; hired plant books nothing,
  >   because its supplier invoice is the GL's record and reaches the job through §5. The log is still *priced* on a
  >   hired machine — that figure is the left-hand side of the match — so "approved but no cost" is a real state and
  >   the register says so in a column rather than leaving somebody to read the service.
  > - **Three unit columns, because plant is charged three ways.** Working, idle and standby are separate rates in
  >   every hire agreement, and one blended column would make whoever fills the sheet do the blending in their head —
  >   losing the figure that answers "what did we pay for a crane to stand still", which is among the most recoverable
  >   costs on a job. `downtime_reason` is what makes it arguable later, and the register has a filter for it.
  > - **A null rate means not charged, never "fall back to the working rate".** The fallback would inflate every job
  >   that ever had a machine standing, silently. The screens print "not charged" so the company's choice is visible on
  >   the row. And the unit rate on the cost entry divides by the **chargeable** units only: eight charged hours and
  >   four uncharged idle ones is eight, and dividing by twelve would report a rate two thirds of the one that was set.
  > - **A machine with no rate cannot be approved against.** §18.1's healthy-looking figure hiding an absence, and
  >   worse here than on labour because nobody expects a machine to be free. The register has a *No rate set* filter so
  >   it is findable before somebody hits the refusal.
  > - **The meter is evidence, not the basis of the charge.** Engine hours legitimately differ from charged hours, so a
  >   mismatch is never refused — refusing it would refuse the ordinary case and teach everybody to leave the readings
  >   blank, which loses the evidence entirely. A reading that went *backwards* is refused, because a meter cannot.
  > - **The match is cumulative and stores nothing.** A hire invoice covers a month and is dated after it while the
  >   logs are dated within it, so filtering both to one period would report a difference on every machine every month
  >   and the report would stop being read. It reuses §5's `MatchTolerances` minimum rather than inventing a second
  >   tolerance, and it stores no acceptance because §5 already keeps that decision on the commitment line the invoice
  >   was allocated to — a second one would be two records of one decision.
  >
  > **Rates live on the plant item rather than in a dated table, and that asymmetry with §7.2 is deliberate.** A labour
  > rate is a ladder — five tiers, dated, varying by job, trade and person — and revising the company default reaches
  > every job at once. A plant rate is one number on one machine, set when it joins the fleet by the same person who
  > registers it. So the protection is the snapshot on the log alone: a revision re-prices outstanding drafts and leaves
  > approved logs alone, and there is no way to schedule a change or charge one job differently.
  > `construction_plant_rates` in the shape of `construction_labour_rates` is the extension if a customer needs either;
  > this is a smaller design on purpose, stated in `PlantItem` so nobody reads it as unfinished.
  >
  > `RoleGrantsTest::EXPECTED` moved to 43 / 123 / 154 / 174. Four permissions, and **no separate rate permission** for
  > the reason above — a fifth name for a decision nobody makes separately.
  >
  > **One process note, because it cost a full-suite run.** The suite was started in the background and then kept
  > editing over: it read a `module.php` that granted plant permissions the same file did not yet declare, and 1,355
  > tests failed on a `RoleSeeder` refusal that had nothing to do with any of them. A full run is only meaningful
  > against a tree that stops changing while it runs.
  >
  > **7d built 2026-08-19 — Phase 7 complete.** — `ConstructionTimesheetImportTest` (22 tests).
  > `TimesheetLabourImport`, a `TimesheetImportSummary` to carry its result, and the *Import from timesheets* action on
  > the site-sheet register. No migration, no model, no permission: the import writes `construction_labour_records`
  > through `LabourRecordService` and rides on `ConstructionLabourRecord`, because importing a day's work is the same
  > act as typing it.
  >
  > Five decisions worth carrying forward:
  >
  > - **Copied, not read in place, and §7.1 gives the reason as a fact about the other table.** `timesheet_entries`
  >   bills and never costs — its ladder resolves *charge-out* rates — so reading those entries as cost prices a job at
  >   what the client is billed and overstates every margin by the mark-up. The entry becomes a draft and §7.2's ladder
  >   prices it. A test approves an imported record and asserts the construction rate, which is the assertion that
  >   makes "billing keeps its ladder; costing gets its own" true rather than said.
  > - **Drafts, never approved.** An import that booked cost would bypass the approval that snapshots the rate and let
  >   a timesheet entry reach a job with nobody having read it.
  > - **Four prerequisites, each reported by name rather than guessed**: approval, a job that names the entry's project,
  >   a worker linked to that employee, and a cost code from that worker's trade. **No worker is created on the fly** —
  >   a register that fills itself from timesheets is a register nobody chose the contents of, which is the opposite of
  >   §7.1's argument for having one.
  > - **One bad row never loses the file**, following Attendance's importer. The most useful case is the day-length
  >   guard firing: the same day on a site sheet and on a timesheet is double-counted labour, and it arrives as one
  >   named row among ninety-nine that went in.
  > - **Idempotent on the `source` morph, including reversed records.** Re-running a fortnight is how somebody checks
  >   they got everything, so `alreadyImported` is counted apart from `skipped` — "42 were already in" is reassuring
  >   where "42 skipped" sends them looking for a fault. A record somebody *reversed* deliberately does not come back:
  >   re-importing it would undo a decision and look like the import working.
  >
  > **The bridge to a job is `construction_jobs.project_id` read as an integer**, which is what keeps `projects` out of
  > this module's import graph while still letting the two meet. `KNOWN_COUPLINGS` gains `construction_costing ->
  > timesheets` only, in both lists, and the graph stays acyclic.
- **Phase 8 — Materials.** `stock_locations` in Inventory, the movement-type enum expanded once for both
  plans, material issues and returns, materials on site.

  > **8a built 2026-08-19.** — `StockLocationTest` (16 tests), plus 3 in `ConstructionGoodsReceiptTest` for the
  > store path. `stock_locations` and `stock_movements.stock_location_id` in Inventory,
  > `construction_jobs.stock_location_id` here, the enum expanded once, `InventoryValuationService` and
  > `InventoryService` taking a location, and `GoodsReceiptService`'s store path built where Phase 5c refused it.
  >
  > Six decisions worth carrying forward:
  >
  > - **The table is Inventory's, and that is the whole point.** Either plan building its own would have made the
  >   other depend on it or invent a second nullable location column — §6's "wrong at every location and correct in
  >   total, which is the hardest class of wrong to notice". A test asserts `stock_movements` has exactly one location
  >   column and no `store_id`, because the wrong version of this is a column somebody adds in good faith.
  > - **The FIFO lots are scoped to the location, and that is the half that would have been quietly wrong.** Scoping
  >   `onHand()` alone is the obvious change; leaving `costOfSale()` unscoped would let an issue on site consume the
  >   cheapest lot in a warehouse forty miles away, and both locations' valuations would drift with nothing
  >   disagreeing. The sufficiency check is scoped with it, or an issue could be priced out of stock somewhere else.
  > - **`null` means "not tracked by location", never "unknown".** The retail plan's original shape was
  >   nullable → backfilled → **non-null**; it is left nullable, because the constraint would have to hold for every
  >   historical row in every tenant and every future caller including imports, and `InvoiceService` has no location
  >   to give it until `stores` exists. `onHandByLocation()` therefore reports unlocated stock as its own row rather
  >   than folding it into a location — the fold is how a report produces §6's failure.
  > - **The backfill runs only where movements exist.** A fresh tenant getting a phantom "Main store" is a row
  >   somebody has to work out the meaning of, and a contractor who buys everything direct to site should have no
  >   locations at all. Where there *is* history it has to land somewhere, or every per-location figure understates
  >   while the total stays right.
  > - **Phase 5c's blanket refusal became three specific ones.** A store-destined receipt line needs Inventory, a
  >   product, and a store on the job; each absence names itself and what to do instead. It is still a refusal rather
  >   than a quiet fallback to direct, for the reason Phase 5c gave and which has not stopped being true.
  > - **The store movement is written directly, not through `InventoryService::purchase()`.** That method posts debit
  >   Inventory / credit Cash, which is wrong twice here: the money is owed to a supplier rather than paid, and the
  >   cost has already reached the job as the accrual beside it. §6 draws the same line — "the module owns the
  >   document, Inventory owns the movement" — and `InvoiceService::recordMovement()` is the existing precedent.
  >
  > `StockLocation` rides on `ProductView`/`ProductUpdate` rather than gaining permissions of its own: a location is
  > part of the same answer as a product — what stock, and where — maintained by the same person in the same sitting,
  > so `RoleGrantsTest::EXPECTED` is untouched. `KNOWN_COUPLINGS` gains `construction -> inventory` (the job's store
  > picker) and `construction_costing -> inventory` (the receipt's movement), both guarded, and the graph stays
  > acyclic.
  >
  > **What 8b has to decide, written down now because it is the one place a double-count could hide.** A store
  > receipt costs the job *and* stocks the material. §6 says materials-on-site is "delivered, costed, not yet
  > consumed… and it is one query", so the receipt is what costs it — which means an issue must **not** cost it
  > again. The issue's FIFO unit cost is for reclassifying between cost codes (`CostEntry::KIND_RECLASS` exists for
  > exactly this) and for valuing wastage, never for adding cost. Getting that backwards would charge every stocked
  > delivery twice, and both figures would look like material cost on the same job.
  >
  > **8b built 2026-08-19.** — `ConstructionMaterialIssueTest` (28 tests). `construction_material_issues` and its
  > lines, `MaterialIssueService`, the docket register with its lines tab, and
  > `InventoryValuationService::consume()` — the one thing Inventory had to grow for this.
  >
  > Seven decisions worth carrying forward:
  >
  > - **Posting adds no cost, and every other rule follows from it.** The reclass pair sums to zero and the tests
  >   assert the job's total is unchanged by an issue, by a return, and after a reversal. That assertion is the
  >   deliverable; everything else is how it is achieved.
  > - **The reclass follows the FIFO lots, which is why `consume()` had to exist.** `costOfSale()` returns one
  >   number, which is all a sale needs; an issue needs to know *which lots* it took, because a lot names the goods
  >   receipt it arrived on and that receipt names the cost code. Without that chain an issue would have to guess
  >   where the cost currently sits. `consume()` and `costOfSale()` share one FIFO walk — two implementations of lot
  >   consumption is two answers to "what did this cost", and the second is always the untested one.
  > - **The split is per received code, not per docket.** Two deliveries at two codes, consumed by one issue, produce
  >   two reclass pairs. A single blended pair would take cost off a code that never carried it.
  > - **A reclass across cost types is refused**, and that refusal is load-bearing rather than fussy: keeping the pair
  >   inside one cost type keeps it inside one job-cost account, which is what makes "sums to zero" true in the
  >   general ledger as well as in the job — and that is what lets the pair be `memo` honestly. It is also a real
  >   business rule: material has not become labour by being carried to the work face.
  > - **Where the code is unchanged, nothing is written at all.** Two rows netting to zero on one code are noise on a
  >   report people have to read, and they would double the length of every cost code's history for no information.
  > - **Wastage is its own `waste` movement**, which is the use §6 added that enum value for: "what did we waste" is a
  >   query on a type rather than a column somebody has to remember to subtract. It stays on the job — the company
  >   paid for it — and it is reclassified with the rest, because it was wasted *on that activity*.
  > - **A return goes back at the cost it left at**, against the line it went out on. Revaluing it at today's FIFO
  >   would make a return a way of changing the value of stock without buying anything, and a separate return document
  >   would be a second numbering series for the reversal of a docket somebody is still holding (§6's
  >   `returned_quantity` on the line is exactly this).
  >
  > **One permission**, `ConstructionMaterialIssue`, covering the docket and the posting — the goods receipt's shape,
  > because the storeman signs the paper and the stock moves in the same act. Reading rides on
  > `ConstructionReceiptView` and reversing on `ConstructionCostReverse`. `RoleGrantsTest::EXPECTED` moved to
  > 44 / 124 / 155 / 175.
  >
  > **A repeat worth recording: a Filament `Sum` summariser cannot sit on a `getStateUsing` column.** It goes looking
  > for a database column of that name and the query fails outright. Phase 6c hit this on `OrdersRelationManager` and
  > wrote it down there; 8b hit it again on the docket's *value moved* column. The total belongs on the lines, where
  > `amount` is a real column — which is where it now is. **Two occurrences in two phases means the note needs to be
  > somewhere a reader meets it before writing the column, not only where it was last found.**
  >
  > **8c built 2026-08-19 — Phase 8 complete.** — `ConstructionMaterialsOnSiteTest` (16 tests). `MaterialsOnSite`, a
  > section on the job cost report, and an evidence panel on the payment certificate. No table, no permission, no
  > coupling: §6 promised "one query" and that is what it turned out to be.
  >
  > Five decisions worth carrying forward:
  >
  > - **It reads `remaining_quantity` on the lots, not received-minus-issued.** The FIFO engine already maintains the
  >   unconsumed part of every lot and an issue is what decrements it, so the answer exists — deriving it a second way
  >   would be a second figure to disagree with the first. This is what makes §6's "one query" literally true.
  > - **On the cost report because it is the part of `actual` that has not been used yet.** A code showing 5,000,000
  >   spent with 3,000,000 still stacked by the gate reads as further through its budget than the work is, and nothing
  >   else on that page would say so.
  > - **Grouped by the code the material was *received* against**, because until it is issued that is where the cost
  >   still sits — 8b's reclass is what moves it. Anything else would put a figure on a code the ledger has nothing on.
  > - **Untraceable stock is its own row.** §6's failure is a figure right in total and wrong in every breakdown, and a
  >   test asserts the breakdown sums to the total for exactly that reason.
  > - **On the certificate as evidence, never as the claim.** §6 names the certificate's materials line as the second
  >   reason receipt and issue are separate documents, so this is where the figure earns its place — but what is
  >   *claimed* is assessed at contract rates against a schedule line that allows materials, and it stays on the
  >   certificate lines where §10 puts it. The panel's own wording says which it is.
  >
  > The certificate panel asks `construction_costing`, which holds the Inventory guard itself — so
  > `construction_contracts` reaches only the sibling it already reaches for Phase 6c's commitment relief, and
  > `KNOWN_COUPLINGS` is unchanged. Absent rather than zero in all three cases that have nothing to say: no cost
  > module, no Inventory, or a job with no store.
  >
  > **Two bugs found by reviewing Phase 8 before moving on, both fixed with tests.**
  >
  > - **An unwind read its own previous output.** `MaterialIssueService::unwindReclass()` finds the posting entries by
  >   "negative, kind reclass, this line" — but an unwind writes a pair of its own, and its negative half sits on the
  >   code the material was *issued* to, which matches that filter exactly. A **second** partial return therefore read
  >   its predecessor's row and wrote two more that netted to zero on one code: the totals stayed correct and the cost
  >   report filled with precisely the noise `reclassify()` refuses to write. Posting never puts a negative on the
  >   issue's own code, so `cost_code_id != $line->cost_code_id` separates the two exactly. Worth remembering as a
  >   shape: **a query that finds "what to reverse" will find its own reversals unless something distinguishes them.**
  > - **A lump-sum order line priced its delivery at nothing**, and this one predates Phase 8 — it has been there since
  >   Phase 5c. `GoodsReceiptService::addLineFor()` copied the order line's `rate` straight through; where an order was
  >   placed as a quantity and a lump sum with no rate, the receipt line's own saving hook then left `amount` at zero,
  >   because it only computes one when a rate is present. **The delivery cost the job nothing at all.** Phase 8a made
  >   it worse by stocking the lot at nil value on top, so a full store reported no materials on site. The receipt now
  >   uses the rate the lump sum implies, a part delivery is pro-rata, and a store line with no value at all is refused
  >   rather than stocked — of the two wrong answers, a full store reporting nothing is the worse one.
  >
  > The second is the more useful find, because nothing in Phase 5c's own tests could have caught it: every order in
  > them carries a rate. **A field that is optional in one table and required by a hook in the next is a hole no test
  > of either table alone will find.**
- **Phase 9 — Site operations.** `construction_field`: the daily log and its children, RFIs, submittals,
  punch lists, activities, delay events, and the P6 and MS Project import. **Ends with:** the delay-event
  notice clock and its notification live before anything else in the phase, because it is the piece that
  pays for the rest.

  > **9a built 2026-08-20 — the exit condition is met first, as this phase asks.** — `ConstructionDelayEventTest`
  > (34 tests). The `construction_field` module end to end — registry entry, provider, plugin, console route —
  > plus `construction_delay_events`, `construction_contracts.delay_notice_days`, `DelayEventService`,
  > `construction:check-delay-notices` with `DelayNoticeDue`, and the register built around the clock.
  >
  > Seven decisions worth carrying forward:
  >
  > - **The due date is stored and time-barred is computed**, which looks inconsistent and is not. The date is a
  >   snapshot of a contractual period as it stood when the event was raised — §8's certificate reasoning, and it means
  >   editing the contract's period next month cannot move a deadline somebody has already been emailed about. Whether
  >   the bar has fallen is derived from the dates against the date being asked, which is §12's rule for compliance.
  >   `notice_days` is stored beside the date so it can be explained rather than merely trusted.
  > - **The notice period is read without naming `Contract`.** `construction_field` requires only `construction`, so
  >   the service reads one integer out of `construction_contracts` with a query builder and the form's picker does the
  >   same. A model would have bought nothing and cost the boundary — `KNOWN_COUPLINGS` is unchanged, and a test
  >   licenses only the spine and this module to prove the claim structural rather than declared.
  > - **A late notice is recorded, never refused.** Whether lateness bars a claim turns on prejudice, waiver or the
  >   certifier's discretion, so refusing the entry would delete the only evidence of what happened. The row says the
  >   notice was late; `isTimeBarred()` says nothing about it, and `noticeWasLate()` states the fact instead.
  > - **The warning fires once per threshold, tightening as the date approaches** — 14, 7, 3, 1, 0, then once when the
  >   date passes. Mailed to whoever holds `ConstructionDelayUpdate` rather than whoever determines claims: the second
  >   group can do nothing with it, which is the same call `CheckComplianceExpiry` makes. **And the mail says what will
  >   be lost, not that a date is approaching** — where nothing has been quantified yet, which is usual this early, it
  >   says the whole entitlement goes, because that is worse rather than vaguer.
  > - **Awarding more than was claimed is refused.** A determination answers a claim; sixty days against a claim for
  >   thirty is a different event that nobody has notified. **Awarding nothing is recorded as a rejection**, because a
  >   "determined" event showing zero days reads as an oversight to whoever finds it next year.
  > - **Concurrency is a column and never an opinion.** §13 calls it "the whole argument in most extension-of-time
  >   disputes", so the schema can express it — and what it *means* for entitlement depends on the contract and the
  >   jurisdiction, so nothing in code decides it.
  > - **Site staff can raise events**, the third create grant they hold in this suite after the requisition, the goods
  >   receipt and the site sheet. The clock only works if events are raised early and often, and the people who watch
  >   an access being blocked are on site. `ConstructionDelayDetermine` is Manager's, kept away from whoever raised the
  >   claim.
  >
  > **Two mistakes worth recording, both caught by tests rather than by reading.**
  >
  > - **The warning ladder was read in the wrong direction.** `WARNING_THRESHOLDS` is declared descending for
  >   readability, and iterating it in that order returns the *loosest* threshold reached — five days out would report
  >   14, a warning already sent, and the seven-day one would never fire at all. It is now walked reversed, with the
  >   reason on the method. **A constant ordered for a human reader is not ordered for the loop that consumes it.**
  > - **The new contract column was not in `$fillable`**, so `delay_notice_days` could be migrated, formed and
  >   documented and still never persist — every event silently fell back to the 28-day default. Worth remembering as a
  >   shape: **a column added from another module's migration has a second home to be registered in, and nothing fails
  >   loudly when it is not.**
  >
  > **And one bug 9a caused elsewhere, which is the more interesting of the three.** `RetentionService::aiaSchedule()`
  > took the AIA punch-list holdback as zero and attached a note explaining why — guarded on
  > `modules()->enabled('construction_field')`, on the assumption that the module and the punch list would arrive
  > together. **9a licensed the module for the notice clock and left punch lists to a later sub-phase**, so the guard
  > began answering "the module is here" while the question it actually asks is "is there an open punch value to read":
  > a licensed company got a zero holdback with no reason attached. That is exactly the healthy-looking figure hiding an
  > absence which §18.1 names as one of its two exceptions, and §18.1's own row for `construction_field` promises the
  > opposite — "the holdback is zero **with the reason on the movement**". The note is now unconditional until
  > `construction_punch_items` exists. Worth remembering as a shape: **a module-licence guard is a proxy for "does this
  > data exist", and the two come apart the moment a module ships in sub-phases.**
  >
  > `construction_field` also had to join the construction company profile — `CompanyProfileTest` fails a module no
  > profile licenses, which is the check working. `RoleGrantsTest::EXPECTED` moved to 46 / 126 / 158 / 178.
  >
  > **A structural test this phase should have run and did not**, found three sub-phases later: `construction_field`
  > declares a `Site` navigation group, and `NavigationGroupsTest` asserts the sidebar is made of *exactly* an
  > enumerated list. A new group is a deliberate act that has to be argued for, which is the point of that test — and
  > the argument is the same measurement §18.2 used to split `Contracts` off: the diary and the delay register are
  > opened by the site team at the end of a shift, where `Construction` is the commercial coding of the job. The lesson
  > is procedural rather than architectural: **the structural tests to run after adding a module are not only the
  > module-boundary, alias-lock, role-grant and help ones** — anything declaring navigation, a profile entry or a
  > console schedule has its own gate.
  >
  > **9b built 2026-08-20.** — `ConstructionDailyLogTest` (28 tests). `construction_daily_logs` and three children —
  > manpower, plant and events — with `DailyLogService`, the register and its three tabs.
  >
  > Six decisions worth carrying forward:
  >
  > - **The unique index is the feature, and the service refuses ahead of it with a sentence.** A constraint violation
  >   on a site foreman's screen is not an explanation, and "open the existing one and add to it" is the only useful
  >   thing to say.
  > - **The lock reaches the children.** Approval that stopped at the header would protect the weather prose and leave
  >   the man-hours and plant hours editable — which is the half a claim is built from. A test attempts all three.
  > - **Reopening exists, with an author and a reason, and clears the approval.** Refusing outright would leave a wrong
  >   signed diary wrong for ever, and a company in that position keeps its real diary in a notebook. Clearing the
  >   approval means the day must be signed off again rather than carrying one that predates the change.
  > - **`workable` is the default, deliberately.** A default of `stopped` would make every unfilled diary read as a
  >   claim.
  > - **Plant stays in three columns and the trade in two fields.** Working / idle / breakdown because §16.1 says one
  >   combined column loses the standing-time claim entirely, and breakdown is the contractor's own risk where idle is
  >   not. Idle with no reason is flagged, because that is the hour nobody recovers.
  > - **Exposure hours read approved diaries only** — §17.6's "denominator nobody has". A rate computed from drafts
  >   would move every time somebody edited one, and a safety rate that moves is one nobody trusts. This is the figure
  >   §17 cannot exist without, delivered a phase early because the diary is where it comes from.
  >
  > **The trade and the machine are read without naming `Trade` or `PlantItem`.** Both live in `construction_costing`
  > and this module requires only `construction`, so the pickers query `construction_trades` and
  > `construction_plant_items` directly and snapshot a label onto the row — which also means a diary still reads
  > correctly if the trade is renamed or the cost module is later switched off. `KNOWN_COUPLINGS` gains only
  > `construction_field -> invoicing`, for the company that supplied the men, hidden without Invoicing with a free-text
  > name carrying it: **a diary must never be unfillable because of a licence.**
  >
  > `RoleGrantsTest::EXPECTED` moved to 48 / 128 / 161 / 181.
  >
  > **What 9c has to decide, written down now.** §16.1's delivery child carries `is_materials_on_site`, which it calls
  > "the link that makes G703's *materials presently stored* column defensible rather than asserted" — and Phase 8c
  > already computes that figure from stock. **Two sources for one number is the trap this suite refuses everywhere
  > else**, so 9c owes a decision rather than a table: either the diary's flag is evidence *for* the stock figure, or
  > the stock figure is the authority and the diary line is a cross-check that can disagree visibly. Photos are the
  > other child, and they need the ISO 19650 register's *promote* action rather than a second document store.
  >
  > **9c built 2026-08-20.** — `ConstructionSiteDeliveryTest` (26 tests) and three more on
  > `ConstructionMaterialsOnSiteTest`. `construction_daily_log_deliveries` and `construction_daily_log_photos`, with
  > `SitePhotoPromotion`, two more tabs on the diary and a fix to the certificate's evidence panel.
  >
  > **The decision the sub-phase owed, answered: the stock ledger is the authority and the diary corroborates.** The
  > argument is arithmetic rather than preference — *a flag can only ever accumulate*. Nothing on a diary decreases when
  > material is built in, so a total of flagged dockets overstates materials on site by exactly everything already
  > issued, and that error grows monthly with no error anywhere to find. `remaining_quantity` on the lots goes down on
  > issue, which is why Phase 8c's figure is the one a certificate quotes.
  >
  > **And the flag then earns its place twice over, on questions stock cannot answer:**
  >
  > - **Where there is no cost module there is no stock ledger**, and the flagged dockets are the only record of what is
  >   standing on site. This exposed a real defect in 8c: the certificate's materials panel returned null whenever
  >   `construction_costing` was off — and `construction_contracts` does not require that module — so the section
  >   vanished and a certifier could not tell whether nothing was on site or nothing was being tracked. §18.1's healthy
  >   figure hiding an absence, in the one place a figure is being certified. The panel now shows whichever source
  >   exists and **names it**, and where the diary is the only one it says the quantity has to be verified on site
  >   rather than presenting it as computed.
  > - **A docket with no priced receipt behind it is the exposure**, and it is the same shape as 9b's unnotified event
  >   one document chain along: material received, cost not recorded, margin overstated. §5's three-way match catches
  >   the invoice that disagrees with an order; nothing caught the docket that never left the site hut. Rejected loads
  >   are excluded — nobody should be receipting those — and the whole report is empty without
  >   `construction_costing`, because with no goods receipts every docket would be listed and a control that fires on
  >   everything is one people learn to click through.
  >
  > Four smaller decisions worth keeping:
  >
  > - **The delivery carries no money at all** — no rate, no amount, no cost code — and a test asserts the columns are
  >   absent rather than merely unused. A site docket that priced itself would be a second answer to what a delivery
  >   cost, which is the whole failure this sub-phase is about.
  > - **The docket number is nullable, and the table says *no docket* in plain sight.** Material does arrive with no
  >   paperwork, and a delivery this application refuses to record is one recorded on the back of a drawing.
  > - **Three conditions, and the middle one earns its place.** *Accepted with damage* is the load taken because the
  >   pour was booked; without it people mark it accepted and the fact disappears.
  > - **Photographs are not register containers, and promotion does not publish.** §16.1 keeps them out because thirty
  >   thousand of them bury the drawings the register exists for, then names *promote to register* as the exception. It
  >   creates the container at work-in-progress, gated on `ConstructionDocumentCreate` rather than the diary's own
  >   grant — whoever may write a diary is not whoever may put a container in the register, and that gate is the only
  >   thing keeping the register out of the state the separate table exists to avoid. Publishing is §15's approval gate
  >   and a third permission again; a promotion that published would be a way around it.
  >
  > **One file, two rows.** The register's revision carries the photograph's own path, name, size, mime and sha256
  > rather than a copy of the bytes, so an adjudicator is shown the same image and not a re-encoding. The consequence is
  > enforced: a promoted photograph cannot be deleted from the diary, because a register listing a file nobody can
  > produce is worse than never having promoted it.
  >
  > **No new permissions**, so `RoleGrantsTest::EXPECTED` is unchanged at 48 / 128 / 161 / 181 — the two children are
  > the diary's, and promotion is the register's. **One new coupling**, `construction_contracts -> construction_field`,
  > for the certificate panel: guarded, and the direction is the only one available because the field module never
  > names a class of this one. The delivery's contract item and its goods receipt are read with the query builder as
  > usual — but the photograph's **location and register container are real relations**, because §16.5 and §15 both put
  > those in the spine, which this module requires. That is the packaging plan paying for itself: the two things every
  > field subsystem needs are the two that cost nothing to name.
  >
  > **9d built 2026-08-21.** — `ConstructionRfiTest` (29 tests). `construction_rfis`, `RfiService`, `RfiPolicy` and the
  > register with its two reports.
  >
  > **The column §16.2 does not ask for is the one this register earns its place with.** `delay_event_id`: late
  > information is already a cause on §13's event, an RFI is where such a delay is *first written down*, and the notice
  > period is running from the day the answer was needed. So an RFI marked `time_impact_flag = yes` with no delay event
  > behind it is the third instance of this section's recurring shape — after 9b's unnotified diary event and 9c's
  > unreceipted docket — and the action raises the notice **dated the day the answer was needed**, not today (which
  > time-bars a claim by its own paperwork) and not the day the question was asked (when the work was not yet blocked).
  > `possible` is excluded deliberately: a notice for every impact somebody is still assessing turns the notice register
  > into noise nobody reads.
  >
  > Five decisions worth carrying forward:
  >
  > - **"No gaps" is a rule about deletion, not about counting.** §16.2 asks for `rfi_number` per job with no gaps, and
  >   the register is read sequentially and quoted by number in correspondence — so a hole in it is indistinguishable
  >   from a removal somebody wanted. The model refuses deletion outright; cancellation with a reason is the way out and
  >   the number stays used.
  > - **Closing requires an answer.** An RFI closed with nothing against it disappears from the outstanding list while
  >   the question is still outstanding, which is the one job this register has. Cancelling is the other path and reads
  >   as a different fact.
  > - **The answer is dated the day it was given, not transcribed.** The response time this register reports belongs to
  >   the other side, and dating it from the transcription flatters the party being measured. A late answer is recorded
  >   and marked late — the same shape as §13's late notice, and for the same reason.
  > - **One `Days` column with three meanings, each labelled**: days left while an answer is owed, days *late* once past
  >   due, response time once answered. Three columns would each be blank two-thirds of the time. All computed, per
  >   §16.2's closing line — a stored days-open is wrong by one every midnight.
  > - **Two permissions, not three.** Raising is site's, and recording the answer is the *same* grant: the answer arrives
  >   by email and somebody transcribes it, which is clerical rather than an approval, and a permission there would
  >   leave answers sitting in an inbox while the register says the question is open. What genuinely needed separating
  >   already was: raising the delay event asks for `ConstructionDelayUpdate`, because serving notice on the employer is
  >   not the same act as asking a question. `RoleGrantsTest::EXPECTED` moved to 50 / 130 / 163 / 183.
  >
  > **Two columns §16.2 lists are deliberately absent.** `activity_id` waits for the programme sub-phase, which builds
  > `construction_activities` and adds the column with a real foreign key to RFIs, submittals and punch items together —
  > a nullable integer nothing can populate for three sub-phases reads like an unfinished feature. And there is **no
  > per-round response table**, which §16.3's submittals will get and this does not: a submittal that has been round
  > three times is a schedule risk, whereas an RFI answered unsatisfactorily is re-raised as a new numbered question,
  > which is what the register should show — because the second question has its own clock.
  >
  > **9e built 2026-08-21.** — `ConstructionSubmittalTest` (33 tests). `construction_submittals`,
  > `construction_submittal_reviews`, `SubmittalService`, `SubmittalPolicy`, and the register with its rounds tab.
  >
  > **The submit-by date is computed and the four durations are what is stored** — §16.3's own rule, and the one the
  > whole register turns on. Required on site, less fabrication, procurement, review period and buffer. A test moves the
  > programme date and then a lead time and asserts the answer moves both times, which is exactly what a stored date
  > could not do: "typed, it goes stale the day the programme moves, and a stale submit-by date is worse than none."
  >
  > The finding this produces on day one is the reason it matters: **an item can be late the day it is registered.** A
  > sixty-day fabrication and a twenty-one-day procurement against a date three months out is already ninety-one days
  > past its submit-by, and nobody has done anything wrong — nobody did the subtraction when the programme was agreed.
  >
  > **Where the section's pattern stops, and this is 9e's own contribution to it.** The three exposures 9b, 9c and 9d
  > surfaced are all money somebody else owes. A submittal late to submit is the contractor's own risk — it is the party
  > that submits — so the register ranks it and chases it and **offers no notice at all**, which a test asserts by
  > checking that a badly-late item produces no delay event and appears in no overrun report. The claimable half is a
  > reviewer past their period, and it lives on the round.
  >
  > Five decisions worth carrying forward:
  >
  > - **A row per round, because a status column cannot hold it.** §16.3: "a submittal that has been round three times
  >   is a schedule risk". One round was budgeted for; rounds two and three spend float nobody planned while every
  >   individual step still looks reasonable. Two rounds cannot be open at once, or the register loses count.
  > - **The review period is snapshotted onto the round**, the same way §8 freezes a certificate's retention terms. A
  >   round whose overrun was computed against fourteen days keeps saying fourteen; recomputing against a renegotiated
  >   twenty-one would silently retire an entitlement somebody has already relied on.
  > - **The status is a projection of the latest round, written by the service and never typed.** §16.3 asks for a
  >   status *and* says a status loses the rounds — both true, and the resolution is that the rounds are the record and
  >   the column is an index into them. `update()` drops a posted `status` silently rather than refusing, because a form
  >   posting its whole state is the ordinary case.
  > - **Approved-as-noted clears the item.** It means "build it, with these corrections" — the fabricator starts, so the
  >   schedule is released. Treating it as unapproved would show a job blocked on four hundred items that are all
  >   proceeding.
  > - **Long lead is flagged by hand, not inferred.** Ninety days is long lead on a six-month job and ordinary on a
  >   four-year one, so no threshold here can decide it. The flag is what filters four hundred items down to the twenty
  >   that will stop the job.
  >
  > **A portability trap caught twice in one sub-phase, and worth recording as a rule.** The overrun is a date
  > difference against a *per-row* period, and expressing that in SQL needs a dialect-specific function — `julianday` on
  > SQLite, `DATEDIFF` on MySQL. A first draft had exactly that as a query scope: it would have passed every test and
  > failed in production. The second was `withCount('reviews')->having('reviews_count', '>', 1)`, which SQLite refuses
  > outright as a non-aggregate — the failure landing the right way round for once. **This suite tests on SQLite and
  > ships on MySQL, so a query that only works on one is a query that only works in tests.** Both are now
  > `has('reviews', '>', 1)` and a PHP filter over an indexed narrowing.
  >
  > `RoleGrantsTest::EXPECTED` moved to 52 / 132 / 165 / 185 — two names again, and recording a reviewer's return rides
  > on the same grant as submitting for the same reason the RFI's answer does. `activity_id` is absent here too, for the
  > programme sub-phase to add with a real key alongside RFIs and punch items.
  >
  > **A third portability-and-cost trap, caught by `PanelPerformanceTest` rather than by review, and it is the one worth
  > generalising.** This register was given a navigation badge counting items already past their submit-by date — and
  > since that date is deliberately not a column, the badge read *every* outstanding row and filtered in PHP. The test
  > showed it as `select * from construction_submittals` sitting in the dashboard's query list, one query over budget on
  > both the dashboard and the reports hub.
  >
  > The rule, now stated once for every phase after this: **a navigation badge renders on every page in the panel, so it
  > must be a single indexed count or it must not exist.** §16.3's refusal to store the submit-by date is exactly what
  > rules a badge out here, and the figure is not lost — the register's own column shows it per row and
  > `lateToSubmit()` is the report, both on a page somebody opened on purpose. The RFI's overdue badge and the punch
  > list's blocking-items badge stay, because both are one count against a composite index.
  >
  > **9f built 2026-08-21.** — `ConstructionPunchListTest` (31 tests) plus four rewritten on
  > `ConstructionRetentionTest`. `construction_punch_lists`, `construction_punch_items`,
  > `construction_punch_inspections`, `PunchListService`, two policies and the register with its items tab.
  >
  > **This is the sub-phase where §16 pays for §11.** `affects_practical_completion` is the flag §16.4 says the AIA
  > holdback reads, and `PunchListService::holdbackFor()` is what `RetentionService` now calls. The three answers matter
  > as much as the figure: *unknown* without the field module, *nil* with nothing flagged, or a figure **with the count
  > of blocking items that carry no cost estimate** — because a holdback of 150,000 across three items where one has
  > never been priced is not a holdback of 150,000, and §18.1's rule is that the certifier is told rather than left to
  > discover it.
  >
  > **The note that had been unconditional since 9a is closed, and the reasoning is the transferable part.** The guard
  > used to read `modules()->enabled('construction_field')` on the assumption the module and the punch list would arrive
  > together; 9a licensed the module for the notice clock and left punch items three sub-phases later, so the guard
  > began answering *"the module is here"* while the question it actually asks is *"is there an open punch value to
  > read"*. **A licence is not a proxy for data existing.** Both questions are now asked separately and each has its own
  > sentence.
  >
  > Five decisions worth carrying forward:
  >
  > - **Closing an item is what a passed inspection does, not a status somebody sets.** The service drops a posted
  >   `status` outright, and `inspect()` is the only route. This is the segregation the register needs and it is
  >   *structural rather than granted* — which is stronger, because a `ConstructionPunchClose` permission can be given to
  >   the person who caused the defect and a missing passed re-inspection cannot be given away at all. That is why 9f
  >   adds two permissions where a third looked obvious.
  > - **A row per re-inspection**, per §16.4: "closed after three failed re-inspections is a different fact from closed
  >   first time". Counted, never inferred from status history — the discipline `tickets.reopened_count` already keeps.
  >   `partial` is the third result and earns it: a snag half done is the commonest first-visit outcome, and recording it
  >   as a failure loses the progress while recording it as a pass closes an item that is not done.
  > - **The flag defaults off.** Most snags are paint and sealant; a default of on would hold retention against every one
  >   of them and make the figure meaningless inside a week. The judgement is made item by item, which is what gives the
  >   holdback its standing.
  > - **`internal` is kept apart from `client`.** An internal sweep is the contractor's own quality check; merging it
  >   into the employer's list puts the contractor's own findings into a document that can be quoted back, which is the
  >   fastest way to teach a site team to stop writing anything down.
  > - **Rejection is a status with a reason, and a list cannot close over open items.** "We agreed this was not a defect
  >   on the 14th" answers a question somebody asks again in month nine; a closed list with open items on it is a
  >   handover certificate nobody should have signed, and the refusal names the count because "three outstanding" is
  >   actionable where "cannot close" is not.
  >
  > **The photographs are file columns on the item**, and the reasoning completes 9c's. §16.1 kept site photographs out
  > of the ISO 19650 register; a punch photograph is not a diary photograph either, because it belongs to the *item's*
  > lifecycle rather than to a day — and the before-and-after pair is the whole point of it, which two columns side by
  > side make structural instead of something somebody has to remember to keep together.
  >
  > **The exposure this register carries** is a priced defect with a responsible party and no back charge behind it:
  > cost absorbed, margin down, nothing wrong anywhere. `RoleGrantsTest::EXPECTED` moved to 54 / 134 / 167 / 187.
  >
  > **What remains of Phase 9 is the programme**, and it is now the only thing three registers are waiting on:
  > `activity_id` is deliberately absent from RFIs, submittals *and* punch items, for one migration that builds
  > `construction_activities` and adds the column to all three with a real foreign key.
  >
  > **9g built 2026-08-22.** — `ConstructionProgrammeTest` (32 tests). `construction_activities`,
  > `construction_activity_predecessors`, `ProgrammeService`, `ProgrammeActivityPolicy`, the register with its
  > predecessors tab, and `activity_id` wired into the registers that were waiting for it.
  >
  > **"Store the programme; never solve it" is enforced structurally, not just observed.** A test walks
  > `ProgrammeService`, `ProgrammeActivity` and `ProgrammeActivityPredecessor` by reflection and fails on any
  > *self-declared* method whose name reads like a scheduler — forward, backward, recalculate, reschedule, critical path,
  > levelling, solve. A behavioural test would have passed on the day somebody added `recalculateDates()`. (It also
  > taught the obvious lesson about reflection: the first version failed on Eloquent's own `resolveCustomBuilderClass`,
  > because "resolve" contains "solve" — hence the declaring-class filter and a floor on how many methods it checked, so
  > a test that checked nothing cannot pass forever.)
  >
  > **The exposure, and it is the largest of the five:** days a priced contract milestone is late against the *accepted*
  > programme, less the extension of time *awarded*. Two design decisions make it computable and both are §13's:
  >
  > - **Baseline and planned are two pairs of dates.** A test asserts they give different numbers on the same activity —
  >   15 days late against the baseline, 10 against the plan. One pair would let every re-programme silently retire the
  >   delay that caused it, and a job that has re-programmed around its own delays would report zero.
  > - **`ld_applies` is separate from `is_contract_milestone`**, because a contract names dates it does not price.
  >   Sectional completion of a car park may be contractual with no damages against it, and levying damages against a
  >   planner's marker is what that separation prevents.
  >
  > Four more decisions worth carrying forward:
  >
  > - **Predecessors are stored and never solved.** A test records a finish-to-start link with a five-day lag and asserts
  >   every date on the successor is byte-for-byte unchanged. What the links are read for is `blockedBy()` — "cladding
  >   cannot start, and the two activities in front of it have not started either" is a conversation; "cladding cannot
  >   start" is not.
  > - **Progress is its own permission, and the first third name in this module.** Percent complete and actual dates are
  >   what §14's earned value is computed from, and the person who reports 80% is not usually the person who owns the
  >   consequence of it being 60%. There is deliberately *no* fourth name for the baseline: this application does not
  >   accept programmes, it stores what P6 exported, so guarding a column only an import writes would be theatre.
  > - **Two progress combinations are refused because they are not facts**: 100% with no actual finish, and an actual
  >   finish below 100%. And progress needs a `data_date` — 40% as at the 1st and as at the 30th are different facts, and
  >   without it neither can be compared with last month.
  > - **`external_id` is unique per job *and source*.** The accepted programme from P6 and a subcontractor's fragment
  >   from MS Project can carry the same activity id meaning different things, and a key that collided would make the
  >   second import overwrite the first.
  >
  > **A correction to what 9f's entry promised.** It said the programme sub-phase would add `activity_id` to RFIs,
  > submittals *and punch items*. It adds it to RFIs, submittals and **delay events** — §13's "somewhere to hang a delay
  > event" — and deliberately **not** to punch items, because §16.4 never asked for one and the reason holds: a snag is
  > located in *space*, which is what its location and grid reference are for, and the activity that built the thing is
  > finished by definition. A test asserts the column's absence rather than leaving it as an intention.
  >
  > **A naming collision worth recording:** `App\Models\Activity` is already CRM's morph alias, and `ModuleManifest`
  > refuses a duplicate at boot — which is the guard working. The classes are `ProgrammeActivity` and
  > `ProgrammeActivityPredecessor`, which reads better anyway: a CRM activity and a programme activity are not the same
  > kind of thing. `RoleGrantsTest::EXPECTED` moved to 56 / 136 / 170 / 190.
  >
  > **The badge rule needed a second half, and `PanelPerformanceTest` is what forced it.** 9e's rule was *a badge must be
  > a single indexed count or it must not exist*. By 9g `construction_field` shipped five registers and each of them
  > wanted one — five counts on every page in the application, which the dashboard and reports budgets both rejected.
  > Two were also worse than a count: the delay register had been reading every awaiting-notice event since 9a just to
  > pick a shade of red, and the programme's first badge eager-loaded delay events to subtract awarded time.
  >
  > So: **a badge earns its per-page query only where the failure it warns about is silent and time-barred** — where not
  > looking today permanently costs money. Two survive, and both are contractual windows that *close*: §13's notice clock
  > and §16.2's overdue RFIs. A missed notice is gone and no screen recovers it. The punch-list holdback and the
  > liquidated-damages exposure are money **held or accruing** — visible the moment somebody opens the certificate or the
  > programme, and not extinguished by nobody looking today — so they live in the registers' own columns and filters. The
  > budgets were left untouched, which is the point of having them.
  >
  > **9h built 2026-08-22, and Phase 9 is complete.** — `ConstructionProgrammeImportTest` (22 tests). `ProgrammeImport`
  > with three parsers, `ProgrammeImportSummary`, the import page, and `construction.programme.hours_per_day`.
  >
  > **The decision that carries the money is what an import is allowed to overwrite.** A contractor sends a P6 update
  > every month; if each one rewrote the baseline, every re-programme would silently retire the entitlement it was
  > caused by. So `MODE_UPDATE` — the default — writes planned dates, actuals, progress, float and criticality and
  > **leaves the accepted programme alone**, while `MODE_BASELINE` writes it deliberately and records the revision. A
  > test imports a file that slips the slab by six weeks and asserts the plan moved, the baseline did not, and the
  > lateness against the baseline is 42 days where against the plan it is zero — which is §13's argument for two pairs
  > of dates, demonstrated rather than asserted. The first import into an empty programme seeds the baseline either way,
  > because a job with nothing to measure against has nothing to protect. **And the summary says which of the two
  > happened in words, every time** — "500 activities imported" with that left unsaid is the silence this section is
  > written against.
  >
  > Five smaller decisions worth carrying forward:
  >
  > - **The XER is read by column *name*, never by position.** The column set genuinely differs between P6 versions, and
  >   reading by index is how an importer silently puts a date in a float column. The test fixture deliberately puts
  >   `task_name` before `task_code` and carries a trailing column nothing reads.
  > - **The format is detected from the contents.** An extension is what a mail client decided to call the file; the
  >   first bytes are what the tool wrote. `ERMHDR` is an XER, and the two XML dialects are told apart by what the
  >   document says about itself.
  > - **Units, and they are where an importer is silently wrong.** P6 counts durations, float and lag in *hours*; MS
  >   Project counts slack in *tenths of a minute* — a factor of 4,800 if mistaken for hours. Both become days through
  >   `hours_per_day`, which is **configuration rather than a constant** because a ten-hour shift would overstate every
  >   float figure by a quarter, and float is what a delay argument turns on. Negative float survives: it is P6 saying
  >   the programme is already impossible, and clamping it to zero would delete the most important number in the file.
  > - **A row it cannot use is skipped and counted, and the page prints the reasons.** The failure mode of an importer is
  >   not throwing — it is importing four hundred activities out of five hundred and reporting success. A relationship
  >   naming an activity outside the file is normal in a filtered export, so it is named rather than dropped, and the
  >   notification for a run with warnings is persistent because a toast that fades is the same as no message.
  > - **An unreadable date becomes null, never today.** A guessed date on a programme is a guessed entitlement. And the
  >   XML parser runs with `LIBXML_NONET | LIBXML_NOENT`: a programme file arrives by email from another company, and an
  >   XML importer that resolves external entities is a file-read primitive handed to whoever sent it.
  >
  > **An imported network still moves nothing.** A test asserts the milestone's planned finish is exactly what the file
  > said, when a scheduler would have pushed it out by the predecessor's five-day lag. That is what makes a P6
  > round-trip safe, and it is the same property 9g asserted by reflection.
  >
  > No new permissions: the import writes the baseline, so it asks for `ConstructionProgrammeUpdate`.
  >
  > **A pre-existing test fragility surfaced while verifying this phase, and it is worth recording because it will
  > surface again.** `DashboardStatsTest::test_a_disabled_module_takes_its_figure_off_the_dashboard` passes alone and
  > fails when `CrudRedirectsToListingTest` runs immediately before it. Adding one test file to `tests/Feature` shifted
  > the chunk boundaries of a split full-suite run, which is how it was found — and it reproduces on a pristine HEAD
  > checkout, so it is not this phase's. The diagnosis worth keeping: the inventory contribution in
  > `InventoryServiceProvider` guards itself on `ProductView` and **not** on `modules()->enabled('inventory')`, because
  > the design intends an unlicensed module's provider not to boot at all — which `DashboardStatsTest`'s own docblock
  > admits "cannot be simulated in-process". So the assertion is passing for a reason unrelated to what it claims to
  > test, and the reason is sensitive to what ran before it. Left alone here rather than fixed in a construction
  > phase.
- **Phase 10 — QHSE.** `construction_qhse`: ITPs and inspections with real hold-point release, NCRs with
  CAPA and close-out, the one actions table, incidents, permits, toolbox talks, the induction register
  and the indicators. **Ends with:** an NCR that proposes a deduction and never applies one, and a safety
  page that refuses to print a rate it cannot compute.

  > **10a built 2026-08-22.** — `ConstructionItpTest` (35 tests). The `construction_qhse` module end to end — registry
  > entry, provider, plugin, profile, its own navigation group — plus `construction_itps`,
  > `construction_itp_activities`, `construction_itp_activity_parties`, `construction_inspections`,
  > `construction_inspection_checks`, `ItpService`, `InspectionService` and two registers.
  >
  > **Everything here follows from taking `point_type` seriously**, which §17.1 calls "the entire reason an ITP exists".
  > A hold point stops work; a witness point invites somebody and proceeds without them; a review point is paperwork.
  > Three sentences, three commercial positions.
  >
  > Six decisions worth carrying forward:
  >
  > - **The point type and the notice period are snapshotted onto the inspection at request time.** A test revises the
  >   plan so the point becomes a *review* point and asserts the inspection still says *hold* — an inspection carried
  >   out under the old plan was carried out under the old rules, and reading the current plan would retroactively
  >   change what it meant. §8's certificate terms and §13's notice days are frozen for the same reason; this is the
  >   third instance and the pattern is now settled.
  > - **Releasing a hold point is a separate act, a separate permission and three refusals.** `record()` will not write
  >   the release even when asked to directly, and `release()` refuses anything that is not a hold point, anything not
  >   yet inspected, and anything that failed. §17.1: "a hold point that releases nothing and blocks nothing is a
  >   checkbox with extra steps."
  > - **A hold point that names nobody cannot be issued**, and the refusal names the sequence numbers. Checked at *issue*
  >   rather than at row level, because a plan halfway through being written legitimately has a hold point with no
  >   parties yet. A review point needs nobody — it is documentation only.
  > - **Party and role are separate columns on the pivot.** One point can need the Engineer to *approve* and a laboratory
  >   to *verify*; folding the role into the party would lose which of them the work is waiting on. The relation is
  >   ordered as entered, because an ITP is a document somebody compares with last month's copy.
  > - **A revision is a new plan that supersedes the old one**, with every point and every party copied. A past
  >   inspection keeps pointing at the document that was in force when it happened, which is the only reason ITPs carry
  >   revisions at all.
  > - **`passed` on a check-sheet line is nullable, not a boolean.** A sheet is filled in as the inspection proceeds, and
  >   a false default would read every line nobody has reached yet as a failure.
  >
  > **The witness-point evidence is the quietly valuable part.** §17.1 says work may proceed past a witness point when
  > the invited party does not attend — true only if the register can show they were *told*, so `notified_on` is separate
  > from `requested_on` and `witness_attended` is a column rather than inferred from a name being filled in. The register
  > flags short notice too, because that is the other side's first answer to "you went ahead without us".
  >
  > §17.6's first leading indicator lands early because the data is here: **hold points released at the first attempt**,
  > null rather than a percentage where nothing has been inspected. 100% first-time on a job with no inspections is the
  > flattering wrong answer §17.6's whole section is written against.
  >
  > **The module requires only `construction`**, with `construction_field`, `construction_contracts`, `employees` and
  > `invoicing` all guarded — ISO 9001 and ISO 45001 certification is frequently the *reason* a contractor buys
  > software, and a quality module that needed the books would be unsellable to exactly that customer. Only
  > `construction_qhse -> invoicing` is a class-level coupling; the contract item is an unconstrained integer as usual.
  > `RoleGrantsTest::EXPECTED` moved to 59 / 139 / 176 / 196.
  >
  > **`Quality & Safety` is a fourth construction navigation group**, by §18.2's own arithmetic: `Site` already carries
  > seven entries, and these six registers would take it to thirteen. They are also a different person's screens on a
  > different day — a quality engineer releasing a hold point and a foreman writing the diary are not the same visit.
  >
  > **And a budget moved, for the first time in this plan.** `PanelPerformanceTest`'s Employees page-size ceiling went
  > 360 -> 400 KB: the domain rail carries every group's tree on every page, so a new navigation group makes every page
  > bigger, and that is the budget measuring exactly what it is for. **Worth distinguishing from the query-count
  > failures in the same file**, which were waste — a badge reading a whole table, a badge eager-loading a relation —
  > and were fixed rather than budgeted for. Raise a ceiling for markup a new screen legitimately adds; never to make a
  > page that got heavier for no reason pass.
  >
  > **10b built 2026-08-22.** — `ConstructionNcrTest` (28 tests). `construction_ncrs`, `NcrService`, `NcrPolicy`, the
  > register, and `NcrDeductionOffer` on the *contracts* side.
  >
  > **The property this sub-phase exists to prove is a negative one, and the exit condition of the whole phase asks for
  > it: an NCR never deducts.** §17.2 is worth quoting in full because the code is shaped entirely by it — "it
  > *proposes*; the certification service **offers** the deduction as a row on the certificate that a human confirms and
  > signs for. FIDIC 14.6 permits the Engineer to withhold; it does not require it. A deduction appearing on a
  > certificate that nobody decided on is the fastest available route to a dispute, and it will be the contractor's
  > dispute, because the client's copy has already left the building."
  >
  > So: `proposeDeduction()` is the strongest verb in the quality module, and a test asserts it writes **zero rows** in
  > `construction_certificate_deductions`. `NcrDeductionOffer::offersFor()` is a *read* — no observer, no scheduled job,
  > nothing that runs while a certificate is being assembled. `take()` writes the row only when called with a
  > certificate, an amount and a person, and it marks it **`is_automatic = false` with `approved_by` filled in** — two
  > columns that already existed for exactly this distinction, since `AUTOMATIC_KINDS` is the set the certificate
  > computes for itself and an NCR deduction is deliberately not in it.
  >
  > **And `deduction_certificate_id` is written on the contracts side, never by the quality module.** §17.2 names the
  > precedent and it is exact: `final_settlements.payslip_id` is a nullable column recording which path paid a
  > settlement, which the settlement never writes. *A proposal, not a posting, visible in the import graph* — and the new
  > `construction_contracts -> construction_qhse` entry in `KNOWN_COUPLINGS` is where it is visible.
  >
  > **The amount is a decision, not a copy.** `take()` defaults to what was proposed and accepts less, because
  > withholding less than the quality team assessed is the ordinary outcome of a conversation about it — and a version
  > that copied the figure would make that conversation unrecordable. More than proposed is refused: it is a decision on
  > its own terms and belongs in its own deduction, so the certificate can say where the figure came from.
  >
  > Five more decisions worth carrying forward:
  >
  > - **A disposition is chosen, never defaulted**, and `raise()` drops one that arrives with the form. It is "the field
  >   that decides whether money changes hands", and a default of `rework` would settle that on every new row before
  >   anybody had looked at the work.
  > - **A concession needs its reference.** Asking the client to accept nonconforming work and not recording what they
  >   said is how a job ends up with an as-built nobody can defend.
  > - **Closing needs a re-inspection that passed, and not the one that failed.** Verifying an NCR against its own
  >   failure "proves the opposite of what it claims" — a test asserts all three refusals.
  > - **CAPA is two pairs**, and the register reports `fixed, not prevented`: the corrective action done and the
  >   preventive action outstanding, which is the pour fixed and the reason it happened left alone. That is the commonest
  >   CAPA failure and it is invisible in any design that merges the two fields.
  > - **Severity is not a proxy for cost.** Critical means structural adequacy, safety or a statutory requirement.
  >   Deriving it from `cost_impact` would make a cheap structural defect look minor, which is the one direction that
  >   gets somebody hurt.
  >
  > **The exposure this register carries** is accepted nonconforming work with nothing proposed against it: *use as is*
  > or *concession requested*, no deduction proposed, no back charge — the client took less than the specification and
  > got nothing for it. Sixth instance of this section's shape, and the first where the money is leaving in the other
  > direction.
  >
  > `RoleGrantsTest::EXPECTED` moved to 61 / 141 / 179 / 199. **Three names, and deliberately not a fourth**: proposing a
  > deduction rides on `ConstructionNcrDisposition` because the two decisions are made in the same conversation and the
  > proposal withholds nothing. There is no `ConstructionNcrDeduct` because the act that moves money is on the far side
  > of the module boundary.
  >
  > **10c built 2026-08-22.** — `ConstructionQhseActionTest` (21 tests). `construction_actions`, `QhseAction`,
  > `ActionService`, `QhseActionPolicy`, the register, and an actions tab on the NCR.
  >
  > **One table over five sources**, per §17.4: "four separate action tables produce four *overdue actions* reports that
  > never agree, and the safety manager's one genuinely useful screen — everything overdue, from every source, in one
  > list — becomes a four-way union nobody maintains." Polymorphic over the QHSE objects, with `job_id` denormalised
  > beside the morph because every report on it is per job and reaching the job through five parent types would be five
  > joins on every row of the one screen the table exists for.
  >
  > **The register has no create page**, and that is the design: an action is raised *against* the finding that produced
  > it. An action with no subject is a task in a quality register, and this is not a task manager.
  >
  > Four decisions worth carrying forward:
  >
  > - **The assignee is three columns and the plain name works alone.** On most sites most of the people who have to do
  >   something are a subcontractor's and are in no table here — the same argument §17.3 makes about an injured person's
  >   name. `raise()` refuses an action with nobody against it: an action nobody is assigned to is an action nobody does.
  > - **Done and verified are two acts with two permissions.** "Done" is the assignee's claim and the action stays live
  >   showing *awaiting verification*; "verified" is somebody else's confirmation, and `verify()` refuses an action nobody
  >   has claimed. Third instance of this rule after §16.4's passed re-inspection and §17.2's verified NCR — a register
  >   where whoever caused a finding can close it is a register nobody reads.
  > - **`containment` earns its place beside `corrective`.** Cordoning a hole off is not filling it, and a register that
  >   could not distinguish them would report a site as having addressed something when all it did was put a barrier
  >   round it.
  > - **The class is `QhseAction`, not `Action`.** Filament has an `Action`, and a model sharing that name in a resource
  >   file is a bug waiting for somebody's import statement. Second naming collision this phase after `ProgrammeActivity`
  >   — worth noting that the *reasons* differ: that one was the morph map refusing a duplicate at boot, this one is
  >   ordinary readability.
  >
  > **The tension §17.2 and §17.4 create between them is named rather than hidden.** §17.2 puts corrective and preventive
  > action *on the NCR* because ISO 9001 asks for them there; §17.4 asks for one actions list. Mirroring either into the
  > other would be two sources for one date — the trap this plan refuses everywhere else — so they are kept separate and
  > `everythingOverdue()` **assembles both and labels the source of every row**: `Action · NCR` beside
  > `NCR CAPA · corrective`. That is the same discipline §17.6 demands of an exposure denominator and §16.1's
  > materials-on-site panel already follows: where a figure can come from more than one place, the report says which.
  >
  > `RoleGrantsTest::EXPECTED` moved to 63 / 143 / 182 / 202.
  >
  > **And the badge rule got its third refinement, from the same test.** The NCR and actions registers were each given
  > one; `PanelPerformanceTest` put the reports hub over its query budget, and the honest response was to remove them
  > rather than raise the budget. The operative word in the rule is **silent**: §13's notice clock qualifies because a
  > window closes and no screen recovers it, and §17.1's hold point awaiting release qualifies because work is standing
  > still for want of a signature nobody knows is missing. A critical NCR and an overdue action are *loud* — each is the
  > first row of a screen somebody opens daily — so a count of them on every page in the application is spending the
  > whole panel on a number already visible on its own.
  >
  > **10d built 2026-08-22.** — `ConstructionIncidentTest` (26 tests). `construction_incidents`,
  > `construction_incident_witnesses`, `construction_incident_photos`, `IncidentService`, `IncidentPolicy`, the register
  > and a witnesses tab.
  >
  > **Near miss is a kind rather than a checkbox, and the reason is arithmetic rather than taxonomy.** §17.3: "near-misses
  > reported per lost-time injury is the leading indicator that predicts the next one." A near miss has no injury record
  > to hang a flag on — nobody was hurt — so stored as a checkbox it cannot be counted and the ratio cannot exist. A test
  > reports twelve near misses and asserts the ratio is **null** rather than zero, because no injury to divide by is the
  > *good* state and must not read as a bad number.
  >
  > **Reporting is the widest permission in the whole module**, and that follows directly: a grant that made reporting
  > hard would suppress the number it most needs. A site reporting no near misses is not a safe site, it is a quiet one.
  >
  > Five more decisions worth carrying forward:
  >
  > - **`occurred_at` is a datetime**, because shift timing is half the analysis. Hour ten of a twelve-hour shift is a
  >   finding; the 14th of August is not. The register prints the hour of the day beside the date.
  > - **The reporting delay is computed, printed on the register, and shown on the form while somebody types.** §17.3
  >   makes it a safety metric in its own right — "a site that takes four days to report a first-aid case is a site where
  >   the next one is not reported at all" — and it is kept from `reported_at` rather than `created_at`, because an
  >   incident typed up a week later from a paper form was reported when it was reported. **What counts as late is
  >   configuration**, because a procedure saying two hours and one saying a shift are not measuring the same thing.
  > - **`is_lost_time` is never inferred from a day count.** A lost-time injury where nobody yet knows how long somebody
  >   is off is the ordinary state for a fortnight, and deriving the flag would classify it as a medical-treatment case
  >   for exactly as long as the reportable clock is running. First aid is deliberately outside the recordable set, since
  >   including it is the commonest way a rate becomes incomparable with anybody else's.
  > - **The injured person's name works alone**, per §17.3 — and so does a witness's. A register that required employee
  >   records would record the witnesses who happened to be on the payroll, which is not the same set as the witnesses.
  > - **A witness statement carries its own date**, and the tab prints the gap in days. One taken on the day is worth
  >   several taken three weeks later, and an investigation that cannot say when it spoke to somebody is one nobody can
  >   weigh. A statement typed with no date is dated on entry rather than stored undated.
  >
  > **The exposure here is the only statutory clock in the module**: reportable to an authority, with nothing recording
  > that anybody told them. It is the module's second badge — the rule's word is *silent*, and a duty with a legal
  > deadline that no other screen watches is exactly that. **Closing is refused twice over**: without a cause recorded,
  > because an incident closed with no cause is a lesson nobody learned; and while a reportable incident has no authority
  > date, because closing one would file a statutory duty as finished.
  >
  > `RoleGrantsTest::EXPECTED` moved to 65 / 145 / 185 / 205.
  >
  > **10e built 2026-08-22.** — `ConstructionPermitTest` (25 tests). `construction_permits`, `PermitService`,
  > `PermitPolicy` and the register.
  >
  > **"A permit is time-boxed, and an expired-but-open permit is the failure mode that kills people."** Everything here
  > follows from that sentence, and the register's third badge is that count.
  >
  > Six decisions worth carrying forward:
  >
  > - **`valid_from` and `valid_to` are datetimes and both are required.** A permit valid "on the 20th" authorises hot
  >   work at four in the morning. A test asserts the permit authorises nothing at 04:00 inside a 07:00–17:00 window,
  >   which is a claim a date column cannot express.
  > - **An extension is a new row and the original's window is untouched.** §17.5: "overwriting `valid_to` destroys the
  >   record of what was authorised when." The extension starts *exactly* where the original ends, so no minute is
  >   covered twice or not at all, the original closes at its own end time, and the extension arrives as a **draft** —
  >   extending is a request and issuing is still a decision. The controls travel with it, so an extension is never a
  >   permit with nothing recorded on it.
  > - **Issuing is refused for a window that has already closed.** Authorising work that is already over is either a
  >   mistake or a back-dated cover, and neither should be quiet. This turned out to shape the *tests* too: the only
  >   honest way to produce an expired-and-open permit is to issue a live one and let time pass, which is what the tests
  >   do.
  > - **Issuing is also refused until the controls that type's procedure turns on are recorded** — hot work without a
  >   fire watch, a confined space without a rescue plan, an excavation without the services scanned. Checked at issue
  >   rather than at draft, because a half-written permit is the ordinary state of a draft.
  > - **Nothing auto-closes an expired permit**, and that is the load-bearing refusal to build a convenience. A permit
  >   quietly marked closed by a scheduled job is a hazard nobody walked back to; expiry makes it visible and a person
  >   closes it.
  > - **`area_made_safe` is asked at close-out, and closing without it needs a reason rather than being refused.** A
  >   permit closed with nobody having walked the area is the sequence that burns a building down an hour after everybody
  >   goes home — but a permit that *cannot* be closed stays open for ever, and then the expired-and-open list becomes
  >   noise and stops being read. So it is allowed, with a sentence, and the register keeps the list of permits closed
  >   with nothing recorded: that is a pattern rather than an event, and a site where it is common is a site where the
  >   close-out is a signature.
  >
  > **`details` is JSON and the shared fields are columns**, exactly as §17.5 asks — thirteen types' fields as columns
  > would be ninety mostly-null ones, and everything in a bag could not answer "what is open on level four right now".
  > **Suspension keeps its reason after resumption**, because a permit suspended when the wind got up and then resumed is
  > a different history from one that ran uninterrupted, and that history is what an investigation reads. And resuming
  > after the window closed is refused: that is an extension, not a resumption.
  >
  > `RoleGrantsTest::EXPECTED` moved to 67 / 147 / 188 / 208. **`ConstructionPermitIssue` is the sharpest segregation in
  > the module** — the person who wants to do the work is the last person who should decide it is safe to — while
  > *suspending* is deliberately on the wide grant, because a permit that can only be suspended by whoever issued it is a
  > permit that stays live while somebody goes looking for them.
  >
  > **And the query budgets moved for the first time**, dashboard 28 → 32 and reports 25 → 29, for this module's three
  > navigation badges: a hold point awaiting release, an unreported reportable incident, and an expired-and-open permit.
  > All three pass the *silent* test. The pairing with 10c is the point — **the rule decides what exists and the budget
  > accommodates what the rule allows**, which is why two badges were deleted then and three are paid for now. The
  > reverse would be the failure: trimming a justified count to fit, or raising a ceiling for one that was never
  > justified.
  >
  > **10f built 2026-08-22.** — `ConstructionSitePersonnelTest` (23 tests). `construction_site_personnel`,
  > `construction_competencies`, `construction_toolbox_talks` and its attendees, `SitePersonnelService`, two policies,
  > two registers, and `construction:check-competency-expiry` with `CompetencyExpiring`.
  >
  > **A name is all that is ever required**, of somebody on the register and of an attendee at a talk. §17.5: "most
  > attendees on most sites are a subcontractor's labourers." A register that asked for more would list the people who
  > happened to be on the payroll, which is a small and unrepresentative slice of the people on site — the same argument
  > §17.3 makes about an injured person, now made three times in this section.
  >
  > Five decisions worth carrying forward:
  >
  > - **An induction is not a permanent state**, and `never inducted` is kept apart from `induction lapsed` because they
  >   are different conversations: one person has to be put through an induction and the other has to be put through it
  >   again. A single "not inducted" figure would hide which a site has.
  > - **`is_mandatory` is what turns an expiry into a stoppage.** A first-aid certificate lapsing is a gap; a
  >   confined-space ticket lapsing on somebody in a chamber this morning is an emergency. `isClearedToWork()` reads it,
  >   and a test asserts a lapsed *optional* ticket leaves somebody cleared while a lapsed mandatory one does not.
  > - **One row per person per job.** Somebody inducted on the tower is not inducted on the annexe.
  > - **Renewing a ticket clears the warning ladder**, which is why it is an action rather than an edit: a renewed ticket
  >   has to warn again next year, and a stale `expiry_notified_at_days` would silence it for good.
  > - **An attendee's name is snapshotted onto the row even when the register is linked.** A test renames the register
  >   entry and asserts the attendance sheet still says who was there. *Add everybody on the register* is the
  >   seven-in-the-morning convenience, and it is idempotent so pressing it twice does not double the count.
  >
  > **The competency clock is the fourth instance of the notified-at-days ladder** after `employee_documents`, §12's
  > compliance register and §13's notice clock — 60/30/14/7/0, once per threshold, `array_reverse`d so the tightest
  > unwarned one fires. That last detail is written the same way in both places precisely because Phase 9a got it wrong
  > once, and the test here asserts it skips from 30 straight to 7 rather than back to 14.
  >
  > **Toolbox talks count attendance, not talks**, which is §17.6's requirement: forty talks to two people each is not a
  > briefed site. A talk with nobody recorded is **named** rather than counted as zero attendance, because a talk given
  > and not written up is a paperwork gap while a talk nobody came to is a different problem — and only the first is
  > worth chasing.
  >
  > `RoleGrantsTest::EXPECTED` moved to 69 / 149 / 190 / 210. **Two names, and the update grant is deliberately wide:**
  > register-keeping is gate work done at seven in the morning by whoever is at the gate, and a permission that made it a
  > supervisor's job would produce a register that lags the site by a week — which is a register nobody trusts to say who
  > is cleared to work. Toolbox talks share it rather than earning their own, because a second name would only mean one of
  > the two got filled in.
  >
  > **10g built 2026-08-22, and Phase 10 ends here.** — `ConstructionSafetyIndicatorsTest` (32 tests).
  > `SafetyIndicators`, the `ExposureHours` and `SafetyRate` DTOs, `SafetyIndicatorsReport` and its view,
  > `construction_jobs.exposure_hours_source`, and `construction.qhse.rate_base`.
  >
  > The phase's stated exit condition was "an NCR that proposes a deduction and never applies one, and **a safety page
  > that refuses to print a rate it cannot compute**". Both halves now exist, and the second one is a type rather than a
  > convention: **no method on `SafetyIndicators` returns a float.** Each returns a `SafetyRate` that is either a figure
  > *with the base it was computed on* or a refusal *with the reason*, and `display()` — the only thing a Blade template
  > reaches for — returns §17.6's words, "Insufficient exposure data". A caller cannot write `?? 0` by accident because
  > there is no number to fall back from.
  >
  > **§17.6's silent failure was worth the whole sub-phase.** With no diary the denominator is zero and every rate renders
  > as `0.00`, which reads as a perfect safety record and means nobody filled anything in. This is the one place in the
  > module where the plan explicitly refuses graceful degradation, and the reason is that the degraded answer is *more*
  > convincing than the real one. A missing figure prompts a question; a flattering figure ends the conversation.
  >
  > Four decisions worth carrying forward:
  >
  > - **A refusal distinguishes four states, and each is a different job to go and do.** No source named on the job;
  >   a source whose module is not licensed; no *approved* diary in the period; approved days carrying no man-hours.
  >   The last is the subtlest — somebody signed off days that record nobody on site, which is neither an absent diary nor
  >   an empty site, and collapsing it into either would send whoever reads it to the wrong place.
  > - **One source per job, and the report prints which.** §17.6's mirror-image failure is counting the same people from
  >   the diary *and* Timesheets, halving every rate — and **a halved rate is worse than a missing one, because it looks
  >   like a number somebody can act on.** `exposure_hours_source` is nullable with no default, because a default would
  >   have quietly chosen for every job in every existing tenant and been wrong for whichever half keeps the other kind of
  >   record. A test puts identical hours in both sources and asserts 400,000 rather than 800,000.
  > - **The base travels with the figure, structurally.** `SafetyRate` cannot yield a value without one, `baseLabel()` is
  >   printed on every row *and* on the section heading — because a heading is what ends up in a screenshot in a client
  >   pack — and the page can switch base, which visibly changes every figure on it. §17.6's factor-of-five sentence is a
  >   test: one injury in 100,000 hours is `10.00` per million and `2.00` per 200,000, both true of one site.
  > - **The numerator travels too.** `5.00 per million` on two incidents in 400,000 hours is arithmetic on a small sample,
  >   and printing the rate alone invites somebody to read it as a trend.
  >
  > **Nothing is stored, and that is asserted against the schema rather than trusted.** A first-aid case becomes a
  > lost-time case the day somebody does not come back; reclassification is normal, and a stored LTIFR would still be
  > reporting the old kind. One test reclassifies an incident and asserts the rate moves in the same request; another
  > asserts no indicator table exists, so a later phase adding a snapshot for reporting speed has to argue for it here.
  >
  > **Both sources are read with the query builder, not through their models**, which is what keeps `construction_qhse`
  > requiring only `construction` — `KNOWN_COUPLINGS` says this module reaches Invoicing and nothing else, and naming
  > `DailyLogService` would have made that false. The duplicated *rule* (approved diaries only) is written in both places
  > deliberately: a second reader that quietly counted drafts would produce two different safety rates from one site.
  >
  > **The leading indicators are on the same page on purpose.** None needs a denominator, so they survive the absence
  > that silences everything above them — and a page of lagging figures is read once a month by whoever writes the
  > report, while a page with both is read by somebody who can still change the outcome. Every one is null-not-zero for
  > §18.1's reason: 0% induction coverage on an empty register would tell somebody to induct people who are not there,
  > and "0 of 0 inspections planned" reads as a complete quality plan rather than an absent one.
  >
  > No new permission, no new badge. The page rides `ConstructionIncidentView`, and a monthly report is the opposite of
  > the *silent* failure the badge rule exists for.
- **Phase 11 — Reconciliation, WIP and close.** The GL posting service, accruals and their reversal, the
  reconciliation report and its command, WIP snapshots, the period close, and the period summaries.

  > **11a built 2026-08-22.** — `ConstructionGlPostingTest` (33 tests). `construction_control_accounts`,
  > `construction_gl_postings`, `construction_cost_entries.gl_purpose`, `ControlAccount`, `GlPosting`,
  > `ConstructionGlPostingService`, the `PostingPlan` and `PostingLine` DTOs, two policies, the Control accounts and
  > Cost periods screens, and `ConstructionGlPost`.
  >
  > **§4.1's rule is one sentence and the whole sub-phase is its consequences:** where a GL document already exists for
  > a cost, construction posts nothing and mirrors it; where the cost is construction-only, construction posts a summary
  > journal. A supplier invoice, a payment, a stock movement and a payslip already reached the books, and posting them
  > again would state the company's cost twice with both figures looking right.
  >
  > Five decisions worth carrying forward:
  >
  > - **`purpose` is a column §4.2 does not ask for, and posting is impossible without it.** `kind` says what an account
  >   is *for the report*; it cannot say which of two `recovery` accounts absorbs labour burden. So `purpose` names the
  >   rule — and it is **unique in the database**, because a service that found two candidates and took the first would
  >   absorb half a company's burden to one account and half to another depending on insertion order, and no report
  >   would ever say so. The nullability is doing the other half of the work: any number of accounts may be in scope of
  >   the report with no rule attached, which is what the unique index on a nullable column means in both engines.
  > - **The rule is written by whoever records the cost, not guessed by whoever posts it.** `gl_purpose` was added to
  >   `construction_cost_entries` after a first draft inferred it, and §4.5 is what settled it: a goods-received accrual
  >   and a subcontract accrual are the same shape of row owing different accounts, so no inference could tell them
  >   apart at all. The inference survives as a fallback covering rows written before the column existed, documented as
  >   such.
  > - **A missing control account leaves the cost pending and says so, with the figure.** §7.3's failure is the one
  >   being prevented — "charge either and never absorb it and job cost exceeds GL cost by exactly the burden, growing
  >   every month, with no error anywhere" — and the answer is not a suspense account. Posting *what it can* rather than
  >   refusing the whole run is deliberate: one missing account refusing everything would leave the whole period pending
  >   and make §4.2's report unreadable rather than merely incomplete. What keeps that from being quiet is that
  >   `PostingPlan::skipped` travels with every result, the confirmation modal prints it, and §4.2's drill-down carries
  >   pending-by-age as a named cause.
  > - **`GlPosting` is not a `CostBatch`, and the reason matters.** A batch *owns* the entries it created through
  >   `batch_id`, and these entries already belong to the labour run that wrote them; taking their `batch_id` would
  >   break what §3.2 built it for. §4.1's actual requirement is that "any GL line explodes into its constituents in one
  >   query", which `construction_cost_entries.journal_entry_id` meets — so the trail is intact and §3.2's is untouched.
  > - **The journal is dated to the period end, not the run date.** A June run made on the 4th of July belongs in June.
  >   Dating it to today would push a month's cost into the next one every time somebody was late, which is a
  >   restatement nobody asked for and nothing reports.
  >
  > Two smaller ones, both about not fudging. **An entry and its reversal in the same month write no journal line** — a
  > zero-value line says nothing happened, which is worse than the silence it replaces — but they are still stamped as
  > dealt with, because leaving them pending would report them as owed to the general ledger forever. And **a group that
  > nets to a credit swaps the sides** rather than writing a negative debit, which would balance arithmetically and read
  > as nonsense in the ledger.
  >
  > `RoleGrantsTest::EXPECTED` moved to 69 / 149 / 191 / 211. **`ConstructionGlPost` is Manager's rather than
  > Accountant's**, because it is the only act in the suite that writes into another module's ledger and a posting made
  > by the person who approved the cost is a posting nobody checked. Reversing rides on it — whoever may put a figure in
  > the books is who may take it out, and the control is the required reason — and so does nominating the control
  > accounts, which is one screenful of decisions taken once at implementation.
  >
  > The **Cost periods** screen has no create and no edit, and **no reopen at all**: a period is created by the first
  > cost that lands in the month, and §3.4's rule about a late invoice is the door instead. Phase 11e adds the close to
  > the same screen.
  >
  > **11b built 2026-08-22.** — `ConstructionAccrualTest` (36 tests). `AccrualService`, the `AccrualRun` and
  > `AccrualResult` DTOs, `CostBatch::KIND_ACCRUAL_REVERSAL`, `gl_purpose` on the goods-receipt accrual and carried
  > through `CostLedger::reverse()`, and the *Roll accruals into this month* action.
  >
  > §4.5's two accruals: goods received not invoiced, and subcontract work done not certified. Both batched
  > `kind = accrual`, both unwound at period open.
  >
  > **The correction worth recording is the unwind's scope, because the faithful reading of the plan was wrong.** §4.5
  > says accruals "auto-reverse at the opening of the next period", and a first draft restricted the reversal to *earlier*
  > periods accordingly. That is a double-count: §4.5 is not the only thing that raises accruals — `GoodsReceiptService`
  > raises one the moment a delivery is posted, because §5's committed-cost report is worthless if a delivery takes a
  > month to appear. A pass that skipped the current period found that delivery still *outstanding*, raised a second
  > accrual for it, and doubled the job's accrued cost for the month, which is §4.5's own warned failure arrived at from
  > the opposite direction. **The rule is therefore: wipe everything standing, recompute** — and the invariant afterwards
  > is that the only standing accruals are the ones that run raised. Two tests hold it: one asserts a receipt-time
  > accrual is not accrued twice, and one asserts opening the same month twice changes the position by nothing.
  >
  > Repeated runs leave reversal pairs behind, and that is honest rather than untidy: each run is a dated event, the net
  > after any run is right, and the alternative — netting the re-accrual off against whatever is already accrued — is the
  > line-by-line matching §4.5 rejects by name.
  >
  > Four more decisions:
  >
  > - **The reversal is itself `kind = accrual`**, negative, and this is not a detail. §3.5 defines Actual as
  >   `kind != accrual` and Accrued as `kind = accrual`; a reversal written as `kind = reversal` would net out of the
  >   Accrued column into the Actual one, understating a job's actual cost by exactly the accrual — a job that looked
  >   cheaper with nothing in any query to explain it. It is also why this does not call `CostLedger::reverse()`, which is
  >   right for a correction and wrong here on both counts: that method writes `kind = reversal`, and it puts the reversal
  >   in the *original's* period because §3.3 wants a correction to cancel where it happened.
  > - **`accrual_reversal` is its own batch kind.** A `reversal` batch backs out something that was wrong; this backs out
  >   something that was right last month. Sharing one kind would make "how often does this company correct itself"
  >   unanswerable, since twelve routine unwinds a year would swamp the corrections.
  > - **A delivery with no purchase order is reported rather than accrued.** There is no link through which an invoice
  >   could ever be matched to it, so "not invoiced" is unanswerable — and a guess either way would be wrong on half the
  >   deliveries in the country. Over-invoicing is likewise reported as nothing rather than as a negative accrual: a
  >   supplier who has billed more than they delivered is a three-way-match variance, and crediting the job would be the
  >   wrong argument in the wrong place.
  > - **The subcontract accrual is per contract item, because that is the only level that names a cost code.** A
  >   contract-level accrual would have to pick one code for a subcontract spanning six, and picking would be a guess; an
  >   item with no code is named instead. The claim must be `submitted` or `under_review` — a draft is a subcontractor's
  >   working paper nobody has received — and only `issued` or `paid` certificates net it down, because §10.1 keeps the
  >   claim and the certificate apart precisely on the ground that the certifier's figure is not the claimant's.
  >
  > **The subcontract accrual reads `construction_contracts`, `construction_progress_claims` and
  > `construction_certificate_lines` with the query builder and names no class of that module** — because
  > `construction_contracts` already declares an edge to `construction_costing` (§6c's commitment path), so an import the
  > other way would be a two-cycle and `TANGLED_MODULE_BUDGET = 0` would be right to fail it. A test asserts the absence
  > *and* asserts the tables are read, so the claim is about a real temptation rather than a vacuous one.
  >
  > `CostLedger::reverse()` now carries `gl_purpose` through, which was a latent 11a defect: a reversal of burden that
  > dropped the purpose would leave the negative side unruled, so the absorption account would carry the charge with no
  > matching credit and §11a would report the reversal as unpostable forever.
  >
  > No new permission. Rolling accruals rides on `ConstructionCostCreate` — it raises cost entries and nothing else, and
  > the segregation that matters is `ConstructionGlPost`, which is what puts them in the books.
  >
  > **11c built 2026-08-22.** — `ConstructionReconciliationTest` (38 tests). `construction_reconciliations`,
  > `Reconciliation`, `ReconciliationService`, the `ReconciliationResult` and `ReconciliationCause` DTOs,
  > `ReconciliationPolicy`, the Reconciliation report page, `construction:reconcile` weekly, and
  > `ReconciliationUnbalanced`.
  >
  > §4's opening sentence is the whole brief: **"A second ledger that nobody proves is a second ledger that is wrong."**
  > §3 bought the job-cost ledger; this is the invoice.
  >
  > **The arithmetic is §4.2's block, one column of the stored row per line of it**, so a run *is* the printed statement:
  > GL cost on the control accounts, less cost carrying no job, plus job cost still awaiting the general ledger, against
  > job cost recorded, difference nil. The filter on the GL side is `is_posted = true` with the date inside the window —
  > `FinancialReportService`'s exactly, because §4.2 warns that any other filter makes "the two sides disagree about
  > drafts and nobody will know why".
  >
  > Five decisions worth carrying forward:
  >
  > - **"Shown, never spread"**, and a test asserts the strong form: the reconciliation writes no cost entry, ever.
  >   Apportioning unallocated GL cost across jobs would balance the report and put money on jobs nobody charged it to.
  >   It comes off whole, on its own line, and it is *also* named as a cause — because balancing must not be the same as
  >   being satisfied. A company posting half its cost outside the module would otherwise reconcile perfectly.
  > - **A refusal, on §17.6's model.** With no cost control account nominated the difference would be the entire job cost
  >   — a report that reads as a catastrophe and means nobody has set the module up. The page says what is missing and
  >   prints no figure at all. This is the third place in the suite that rule has earned its keep.
  > - **Mirrored entries now carry the invoice's `journal_entry_id`**, added here because §4.2's matching needs it: "this
  >   was posted by somebody else" is not enough for a report that has to say *which* GL cost has a job behind it. It also
  >   makes §4.2's nastiest named cause detectable at all — "cost entries pointing at a journal entry that was later
  >   reversed or unposted" is undetectable unless a mirrored entry points at one. Accounting keeps no reversal flag, so
  >   the detection is a posted `reversing` entry whose `reference` is the original's `entry_number`; and because
  >   `JournalEntryService::reverse()` dates the reversal *today*, a March cost reversed in July leaves March's GL side
  >   intact and July's short, which is exactly why the cause needs its own section rather than the residual.
  > - **The residual is last and isolated**, §4.2 verbatim: "rounding, isolated so it cannot be used to explain anything
  >   else". Several causes deliberately carry a zero amount — pending cost, unallocated GL cost, closed-job cost,
  >   absorption gaps — because each is already a *line* of the statement or reconciles anyway. Counting them into the
  >   residual would make it wrong; listing them is how somebody sees *which* account is missing, which is the difference
  >   between a chase-list and a fix.
  > - **Unallocated purchase invoices render when empty**, which is §4.2's instruction for that cause alone and the only
  >   `renderWhenEmpty` flag in the codebase. §5 calls it "the single most likely silent failure in the module", so a
  >   section that vanished when the list was empty would be indistinguishable from a section nobody had built.
  >
  > **The command is weekly, which is the opposite of every other clock in this suite, and the reason is stated in
  > `routes/console.php`.** §13's notice, §12's compliance and §17.5's competencies all warn about a *deadline* — the
  > tightest threshold is the day itself and a weekly run steps over it. A reconciliation has no deadline: the difference
  > does not worsen for going another day unreported, and investigating one takes longer than a day. A nightly mail about
  > the same unchanged 412,900 is a mail somebody filters into a folder, and then the month it changes goes unread. It
  > reconciles **every open period** rather than the current one — a difference from March is still a difference in July —
  > skips closed ones, and exits non-zero when anything is unbalanced so a scheduler sees it.
  >
  > **Accepting a difference is `ConstructionPeriodForceClose` and fixes nothing**, which is §4.3's fourth mechanism made
  > literal: a test asserts no plug cost entry and no balancing journal, and that the difference is still there on the
  > next look. Running a reconciliation rides on `ConstructionCostView`, because a control nobody may run is not a
  > control. A run is never editable and never deletable — the way to change its answer is to run it again.
  >
  > **11d built 2026-08-22.** — `ConstructionWipTest` (38 tests). `construction_wip_snapshots`,
  > `construction_jobs.percent_complete_method`, `WipSnapshot`, `WipService`, `WipSnapshotPolicy`, the Work in progress
  > report and its help doc.
  >
  > **§4.4 is the one place this plan permits a stored total, and it argues the exception rather than assuming it.** The
  > house rule in `docs/new-module-checklist.md` §10 says compute; §4.4 says a WIP position "is a judgement at a point in
  > time — the surveyor's forecast, the surveyed percentage, the loss provision — not a derivation from immutable facts",
  > and recomputing last March with today's forecast "would silently restate a month that was signed off, reported to a
  > bank and used to compute a bonus". `locked_at` is the whole mechanism, and it is why every judgement input is
  > snapshotted onto the row rather than referenced: a row that pointed at a forecast run would move when somebody
  > re-forecast.
  >
  > Five decisions:
  >
  > - **The method is the job's choice and there is no default.** §4.4: "cost-to-cost, surveyed, or milestone — chosen per
  >   job." A job with none gets no position at all and is **named** at the foot of the report, because choosing for a
  >   company means choosing cost-to-cost, and cost-to-cost reports *more* progress the more a job overspends — so the
  >   default would flatter exactly the job that needs watching. The method is snapshotted beside the percentage, because
  >   62% cost-to-cost is not the same claim as 62% surveyed and a bank asking which will not accept "the system said so".
  > - **Approved variations in the value, pending beside it.** A variation approved at a *provisional* price counts as
  >   pending, on §9's ground that the scope is agreed and the money is not — which is precisely the "about to be in
  >   trouble" state §4.4 wants visible outside the contract value.
  > - **The whole expected loss, immediately.** Two tests: the same 3,000,000 at one per cent complete and at ninety-six.
  >   And the provision **reduces the contract asset**, because a recognised loss is not an asset — a test shows a job
  >   with an apparent asset turning into a liability the month the loss is taken, which is the whole reason the rule
  >   exists.
  > - **Asset and liability are two columns and are never netted**, at job level or in the totals. One job over-billed
  >   does not offset another under-billed; those are two conversations with the bank.
  > - **The journal posts the movement**, and §4.4 forbids having the choice twice — "choose one; two code paths each
  >   choosing differently is the failure." A month that has not moved posts nothing, on §11a's argument about a
  >   zero-value line; a falling position swaps the sides rather than writing a negative debit. Dated to the month end.
  >
  > **Two fixture defects were mine and both were instructive.** A hardcoded certificate period end meant July saw no
  > billings, so every "nothing moved" test moved — the period end is now a parameter with a comment saying why. And a
  > forecast issued in August left July falling back to cost-to-date, which reads as **100% complete**: right as a
  > fallback, since it recognises no loss it cannot see, and wrong as a fixture.
  >
  > No new permission. Locking is `ConstructionPeriodClose`, deliberately the same grant as closing the month: freezing a
  > position and closing a period are one decision at one moment, and separate names would let a month be closed on
  > figures nobody froze. Posting the movement is `ConstructionGlPost`, because §4.1's boundary does not soften for WIP.
  > Computing rides on `ConstructionCostView` — an unlocked position is a report.
  >
  > **11e built 2026-08-22.** — `ConstructionPeriodCloseTest` (28 tests). `PeriodCloseService`, the `CloseChecklist` and
  > `CloseCheck` DTOs, the close / force-close / check actions on Cost periods, the lock and post actions on Work in
  > progress, and the close half of the reconciliation help.
  >
  > §4.3's mechanisms 2, 3 and 4. **The fourth is what makes the third safe**, and it is asserted in the strong form:
  > after a forced close, not one cost entry and not one journal line has been added, and the pending cost is still
  > pending at the same figure. "No plug entry, no balancing figure" is a test rather than a promise.
  >
  > Five decisions:
  >
  > - **A month nobody has reconciled blocks, and that is worse than an unbalanced one.** §4 opens with "a second ledger
  >   that nobody proves is a second ledger that is wrong", so a gate catching only *proved* differences would wave
  >   through every company that never runs the report.
  > - **Two blockers are this phase's rather than the plan's**, both because the failure is silent *and* permanent.
  >   `ConstructionGlPostingService` refuses to post into a closed period, so closing a month with **pending cost**
  >   orphans it from the accounts for good — and the blocker names the control account that is missing, so the fix is on
  >   the screen. And an **unlocked WIP position** keeps recomputing against a month somebody signed, so the figure a bank
  >   was shown and the figure on the screen drift apart with nothing to say when.
  > - **The WIP blocker is proportionate.** A job with no position does not block — WIP is used or it is not, and a
  >   company computing none must still be able to close. A job with a method chosen and no position is a *warning*, and
  >   so are late costs and unrolled accruals. Hidden warnings are how a month gets closed on facts nobody was shown,
  >   which is why `CloseCheck` keeps `passed` and `blocking` as separate fields.
  > - **A balanced close is `reconciled`; a forced one is only `closed`.** §3.4 gives the period three statuses and the
  >   third has to mean something stronger than the second, or the period list cannot tell a month that was proved from a
  >   month that was signed for.
  > - **A forced close records the reason *and every check it overrode*, in words.** "Closing anyway, the client needs
  >   the report" tells whoever reads it in a year nothing about what was known at the time. Even a forced close records
  >   both control totals, so the month somebody had doubts about is not the least explicable one in the book.
  >
  > **`close()` and `forceClose()` share one writer**, and that is the cheapest way to keep §4.3's fourth promise: the
  > forced path runs exactly the same code and differs only in the note it records. And **there is no reopen** — a test
  > asserts no method offers one, alongside the two doors both refusing a closed month with §3.4's sentence.
  >
  > §4.5's unwind moved onto `PeriodCloseService::open()`, which returns the `AccrualRun` rather than the period: the
  > period is a row anybody can look up, and "reversed nothing, re-accrued 400,000" is the sentence somebody needs on
  > screen. No new permission — `ConstructionPeriodClose` and `ConstructionPeriodForceClose` have existed since Phase 2
  > waiting for exactly this.
  >
  > **11f built 2026-08-22, and Phase 11 ends here.** — `ConstructionFullCircleReconciliationTest` (7 tests).
  >
  > §4.3's fifth mechanism, and the plan's own words for what it is worth: **"that is what turns this section from a
  > claim into an assertion."** One of every source type — allocated supplier invoice, GRN accrual, material issue,
  > labour with burden, internal plant, subcontract certificate with retention, overhead allocation — over one month of
  > one job, then post, then reconcile. `difference === 0.00`.
  >
  > **Driven through the real services, and that is the whole value.** `InvoiceAllocationService`,
  > `GoodsReceiptService`, `LabourRecordService`, `PlantService`, `AccrualService`, `ConstructionGlPostingService`,
  > `ReconciliationService`, `PeriodCloseService`. A full-circle test that wrote its cost entries directly would prove
  > the reconciliation's arithmetic and nothing about the seven writers feeding it — which is exactly what §4.3 predicts
  > goes wrong: "a reconciliation written at the end against eight source types that were built without it in mind is a
  > reconciliation that will not balance, and nobody will know which of the eight is wrong."
  >
  > **The subcontract certificate turned out to be a document, not a cost entry.** `construction_contracts` writes no
  > cost row at all — a payable certificate is a *document*, and the cost reaches the job as §4.5's
  > work-done-not-certified accrual until the subcontractor invoices for it and the invoice is allocated. So the source
  > type is exercised as the accrual, from a real claim against a real certificate with retention held, which is where the
  > money actually is. Worth recording because §4.3's list reads as though seven writers of cost entries exist, and six
  > do.
  >
  > Three tests beyond the headline one:
  >
  > - **Every treatment is present**, so the balancing figure is not about an empty month: `mirrored` from the invoice,
  >   `pending` from five sources, `memo` from the material issue, and `posted` existing only after a run.
  > - **A material issue changes neither side.** §6 makes an issue a reclassification, and the test computes the
  >   reconciliation with and without one. If it reached either side, a company issuing from a store daily would never
  >   balance.
  > - **The counter-test, which is the one that makes the green matter.** Delete the burden absorption account and the
  >   month *still balances* — §4.2 counts pending cost as a reconciling item — and the absorption-gap cause names
  >   *Labour burden absorbed*, and the close blocks naming the same account. §4.3's argument has two halves: a
  >   reconciliation that will not balance, and nobody knowing which of the eight is wrong. The headline test proves the
  >   first. This proves the second.
  >
  > **On "the period summaries" in this phase's line above:** nothing in §3 or §4 specifies one beyond §3.4's control
  > totals on `construction_cost_periods` — which 11e records at close, both sides, and the Cost periods screen prints
  > along with what each month still owes the general ledger. Taken as delivered rather than quietly dropped.
  >
  > One defect the full-suite run caught, and it was 11a's rather than this phase's: the Control accounts create and edit
  > pages had no redirect to their listing, which `CrudRedirectsToListingTest` exists to catch and did.

Phase 11 last is uncomfortable and is still right — it needs every source type to exist before it can
prove anything. But **§4's assertion test must be written incrementally from Phase 5 onward, one source
type at a time.** A reconciliation written at the end against eight source types that were built without
it in mind is a reconciliation that will not balance, and nobody will know which of the eight is wrong.

## Risks

Every risk here produces correct-looking output. That is the selection criterion: the ones that throw
will be found.

- **The two ledgers drift and nobody notices.** The structural risk of the whole plan, and the price of
  §3's decision. It does not announce itself; it accumulates. Mitigation is the whole of §4 and
  specifically the five mechanisms in §4.3 — a scheduled reconciliation, a close that refuses, an
  override with a reason, **no plug entry ever**, and a feature test per source type.
- **A purchase invoice posted with no allocation.** The general ledger is perfectly right and the job is
  under-costed, so every margin flatters and no report shows an error. The most likely silent failure in
  the module. Mitigation: an allocation queue that is a screen people work from, and an always-rendered
  section of the reconciliation report showing zero rather than being hidden.
- **Retention netted against cost instead of held as a liability.** Job cost understated by five to ten
  per cent for the life of the job, self-correcting at release, so the margin merely looks good until it
  does not. Mitigation: the full certified value is cost and the retention is a balance-sheet row, tested
  both ways, on both sides of §11 and §12.
- **Burden charged and never absorbed; internal plant charged with no recovery credit.** Same shape,
  different account: the two ledgers diverge by exactly the recovery, growing monthly, and the profit and
  loss is simply missing a credit. The fleet looks free and every job looks expensive.
- **Earned value derived from cost.** Then it equals actual cost, the cost performance index is exactly
  1.00, and every job in the system reads *on budget* forever. All the numbers are present and none of
  them means anything. Mitigation: earned value comes from a physical measurement or the page says it
  cannot be computed.
- **A provisionally priced variation counted in the certified contract sum.** One bare
  `where('status', 'approved')` that forgets `is_price_provisional`, and the certificate certifies money
  nobody agreed. Mitigation: two named scopes, and no bare status filter anywhere in the module.
- **A journal entry reversed in Accounting without its mirrored cost entry.** A cross-module hole no code
  path closes, because an accountant can do it from another panel. Only §4.2's third cause finds it.
- ~~**A negative purchase line silently dropped.**~~ **Fixed in Phase 0, and the risk was mis-stated.** It
  did not fail silently: `purchaseEntryLines()` put a negative amount in `debit_amount`, `postSystemEntry()`
  dropped the leg, and the bill threw `Entry is not balanced: debits 1000000.00 != credits 900000.00`. The
  balancing fixer never saw it — `absorbRounding()` runs inside `translateDocument()`, *before* the filter,
  and only for a foreign-currency invoice. So the bug was a hard refusal on both paths rather than a wrong
  number, which is the better of the two failures. Mirrored the leg flip from `saleEntryLines()`;
  `PurchaseInvoiceNegativeLineTest` covers base and FX, and asserts gross = net + retention.
- **A stored compliance status with a past expiry.** Pays a subcontractor with no insurance while the
  screen says everything is fine. Mitigation: status is computed from dates; only the notification
  threshold is stored.
- **A safety frequency rate of 0.00 that means nobody filled in the diary.** A perfect safety record and
  an empty denominator are the same number. Mitigation: refuse to render, print the rate base, and name
  the exposure source.
- **A voided certificate stranding the next one's `previously_certified` snapshot.** The following
  certificate quietly under- or over-pays by the difference and the total still looks plausible.
  Mitigation: a certificate may not be voided while a later one exists — the correction is a further
  certificate, which is the discipline invoices already keep with credit notes.
- **An expired-but-open permit to work.** The one risk in this document that is not about money.
  Mitigation: `valid_to` is a datetime, expiry is a status the register computes, and an extension is a
  new row rather than an edited one.
- **`stock_movements` gaining `store_id` before `stock_location_id`.** Then a building site is either a
  fake retail store or a second nullable dimension, and on-hand becomes wrong at every location and
  correct in total — the hardest class of wrong to see. ~~Mitigation: raise it against the retail plan's
  Phase 0, in writing, before either module is built.~~ **Done 2026-08-17: decided in favour of
  `stock_location_id` and written into that plan's §2.1 and Phase 2.** The risk is not closed, only moved —
  it now depends on whichever plan builds the migration honouring it, so **Phase 8 here and Phase 2 there
  are the same migration** and the second one to arrive must find the column already present rather than add
  its own.
- **`Str::snake('ConstructionQHSE')`.** Returns a key that is not in the registry, `moduleFor()` returns
  null, and every class with its own `canAccess()` is silently ungated — a construction cost report
  reachable by a company that never bought the module. Mitigation: the casing rule in §18.2 and
  `ModuleCoverageTest`.
- **A plain-column morph write without `ModuleMap::alias()`.** The fully-qualified class name goes into
  the column, and the day the class moves the query stops matching with no error at all. Five columns in
  this suite are exposed; §18.2 names them.
- **A resource in a mapped navigation group that no branch claims.** It keeps its declared label, no
  domain owns that label, and it vanishes from the menu — reachable only by URL and the palette.
  `NavigationTreeTest` is the only thing that sees it.
- **Scope creep from eighteen sections.** This document describes a construction product inside an
  accounting application, and Phases 4, 5, 9 and 10 are each the size of a plan in this folder. The
  module split of §18 is the defence: five commercial boundaries, each sellable alone, with the phase
  boundaries drawn so that stopping after any of them leaves something coherent to sell.

## Suggested first slice

**Phases 0–3.** The spine, the cost ledger, and the four-column report — budget, committed, actual,
forecast, per cost code, rolling up a work breakdown. That is the smallest thing a contractor will pay
for, it is the thing no accounting package does, and it answers the question the request opens with: what
has this job cost against what it was sold for.

Phase 4 alone is the other viable first slice, and which one comes first is a sales question rather than
an engineering one. A contractor who is losing arguments about certificates wants §8–§11 before anything
else; a contractor who is losing money quietly wants §3–§4. They do not depend on each other in either
direction, which is the point of the split.

Everything from Phase 9 onward is genuinely optional per customer. **No contractor buys all five
modules**, and the plan is built so that none of them is in the way of the others.

One honest note on size, in the spirit of the retail plan's: with field operations, document control and
QHSE in scope this is not a feature, it is a construction product inside an accounting application, and
it is the largest thing in this folder. The four-column cost report is the part that earns the rest.
