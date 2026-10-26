# HRMS: plan

> **BUILT — every phase, 0 through 9.** Six modules (`leave`, `attendance`,
> `timesheets`, `lifecycle`, `recruitment`, `performance`), the payroll join, and
> the statutory schemes. Roughly 105 feature tests across `LeaveTest`,
> `AttendanceTest`, `PayslipAttendanceProrationTest`, `TimesheetTest`,
> `LifecycleTest`, `RecruitmentAndPerformanceTest` and
> `StatutoryContributionsTest`; full suite 1748 passing.
>
> **Nothing changes an existing payslip.** Every pay-affecting switch ships OFF —
> `payroll.prorate_on_attendance`, `payroll.pay_overtime`,
> `statutory.provident_fund.enabled` — and the statutory figures are computed for a
> person to enter rather than applied. A company that upgrades and touches no
> setting is paid exactly as before, which is what
> `PayslipAttendanceProrationTest` and `PayslipCalculationSeamTest` assert
> together.
>
> Three deliberate narrowings from what is written below, each argued at its call
> site: only `basic_wage` pro-rates among the fixed columns (§5); overtime money
> goes into `extra_work_hours`, which was already a rupee amount (§4.2); and
> `leave.weekend_days` survives as the fallback for a company without `attendance`
> rather than being removed (§3).
>
> Mechanics common to any new module are in `docs/new-module-checklist.md` and not
> repeated here; this document decides *what* the HR modules are, what they own,
> and what they must not do.

Two findings shape everything below, and both were surprises:

1. **`payslips` already has `total_working_days`, `paid_days`, `lop_days` and
   `leaves_taken` — and nothing computes pay from them.** They are typed by hand
   in `PayslipForm` (lines 116–137), set to `0` by
   `MonthlyPayrollService.php:51-54` under the comment *"Attendance is what
   payroll cannot know"*, and printed on the payslip PDF. `PayslipService` never
   reads them. So the columns are a **display of attendance, not an input to
   pay**. The moment a leave module starts filling `lop_days` with real numbers,
   somebody will expect the net to drop, and it will not. §5 is about that.
2. **An employee has no leaving date.** `employees` carries `date_of_joining` and
   `is_active`, and nothing else about separation. `MonthlyPayrollService`
   filters on `is_active`, so a leaver is paid a whole month or nothing at all.
   Exit, final settlement and gratuity all need a date that does not exist yet.

## 1. What is already built

More HR exists here than the absence of an "HRMS" suggests. The gaps are narrower
and sharper than a greenfield.

| Have | Where | Note |
|---|---|---|
| Employee records, reporting lines, NIC scans, bank details | `employees` module | `manager_id`, reparented on delete |
| Self-service edits via approval | `EmployeeChangeRequest` | the pattern every HR request should copy |
| Salary packages, versioned by date and fiscal year | `employee_settings` | `start_date`/`end_date`/`fiscal_year_id` |
| Pay as data, not columns | `pay_components` + `employee_setting_components` + `payslip_components` | an allowance or deduction is a row |
| Payslips, FBR slabs per fiscal year, annual projections | `payroll` | plus payroll runs with sign-off lock |
| Advances recovered from payroll | `advances` | instalments against payslips |
| Expense claims → reimbursed on the payslip | `expenses` | `pending / approved / refused / settled` |
| Monthly progress reports | `mpr` | self-service, keyed on `user_id` |
| Downline-only visibility | `App\Support\EmployeeAccess` | BFS over `manager_id`, memoized per request |
| Project teams with dated stints | `project_employee` | `role`, `allocation_pct`, `from_date`, `to_date` |
| Per-company e-mail wording, WhatsApp senders | `EmailTemplate` + `App\Support\WhatsApp` | both channels already exist |
| Custom fields, comments, audit trail on any record | Core | reuse, do not reinvent |

**Missing, verified by schema:** no leave, no attendance, no holiday calendar, no
timesheets, no recruitment, no appraisal, no employee documents with expiry, no
issued-asset register, no leaving date, no final settlement.

## 2. Decision: a family of modules, not one HRMS

This codebase sells modules. `advances` and `expenses` are separate modules
already, each two tables and one workflow, each declaring `['employees',
'payroll']`. One large `hrms` module would be the only thing here that a company
cannot buy a part of — and the parts genuinely sell separately: a software house
wants leave and timesheets and no attendance clock; a factory wants attendance
and no timesheets.

| Module | Owns | `requires` | Guarded (soft) | Company profiles |
|---|---|---|---|---|
| `leave` | leave types, entitlements, requests, balances, encashment | `employees` | `payroll` (LOP, encashment), `attendance` | all with employees |
| `attendance` | work patterns, daily attendance, overtime | `employees` | `payroll`, `leave` | manufacturing, trading, staffing |
| `timesheets` | time against a project, billable/non-billable | `employees`, `projects` | `billing`, `invoicing` | services, software house |
| `recruitment` | vacancies, applicants, stages, interviews, offers | — | `employees` (offer → hire) | all with employees |
| `performance` | review cycles, goals, ratings, one-to-ones | `employees` | `mpr` | services, software house |
| `lifecycle` | onboarding/exit checklists, issued assets, documents with expiry, final settlement | `employees` | `payroll`, `leave`, `accounting` | all with employees |

`recruitment` requires nothing: an applicant is not an employee, and a company
hiring its first employee has no `employees` licence yet. The conversion step is
the guarded part — the "Hire" action is hidden when `employees` is off.

**The profile column is not decoration, and it is the strongest evidence for this
section's own argument.** Every module here has to appear in at least one entry
of `config/company_profiles.php` or `CompanyProfileTest` fails — see
`docs/new-module-checklist.md` §1a. Filling it in forced the split to be justified
per module rather than in the abstract, and two rows are what a single `hrms`
module could never express:

- **`attendance` is not for a software house.** A daily row per employee with
  check-in times is what a factory floor needs and what an office of salaried
  engineers will never fill in — leaving `not_marked` accumulating, which §4.2
  spends a paragraph explaining is the state to avoid.
- **`timesheets` is not for a factory.** Time against a project only means
  anything where the project is the billable unit.

One `hrms` module would sell both to both. The profiles say plainly that no
company wants all six, which is the case for six modules stated as a licensing
fact rather than an opinion about tidiness.

`leave`, `recruitment` and `lifecycle` go to every profile that licenses
`employees` — a household with a cook does not run a leave policy, so the
`personal` profile is excluded throughout, and `bookkeeping` has no employees at
all.

Statutory contribution schemes (EOBI, provincial social security, provident fund,
gratuity) are **not** a module. They are money, they post to the ledger, and they
belong in `payroll` as pay components and provisions — §6.

## 3. Where shared HR data lives

### The holiday calendar goes in Core

Leave and attendance both need "is this day a working day for this company", and
so does any future timesheet validation. The precedent is settled: `FiscalYear`
sits in Core *because Payroll and Accounting both use it and neither owns it*
(`modules-plan.md` §3). A holiday calendar is the same shape — a small,
company-wide list of dates that several modules read.

```
holidays        id, date, name, is_recurring, notes            (tenant)
```

**Built.** `HolidayCalendar` in Core exposes `isHoliday()`, `between()` and
`datesBetween()` — the last for fast set membership when the leave generator
loops over a range. Deliberately **no `isWorkingDay()`**: weekends come from work
patterns, which `attendance` owns and which do not exist yet, so this service
answers only what its own table knows.

Types, since §3 originally gave none and `leave` is about to depend on them:
`date` is unique (two rows for one day is a support call), `name` required (an
unnamed holiday is its own support call), `notes` nullable, `is_recurring`
defaults false. The unique constraint is the index the range scans use; there is
no second index on `date`.

**Decided (2026-09-26): the calendar is company-wide, and stays that way until a
second site exists in the schema.** The question was "decide before phase 1";
this is the decision. A schema sweep found no employee-bearing site, location or
branch concept anywhere in the tenant tables — `employees` and
`employee_job_history` carry none, `stock_locations` is inventory, and
construction `site_personnel` is a QHSE induction register mostly of
non-employees. A nullable `holidays.location_id` today would be a foreign key to
a table that does not exist, filled by no data, checked by a working-day
resolution nobody can exercise. Multi-site companies in Pakistan do observe
different local holidays; when an employee-bearing location concept lands in the
schema, add nullable `location_id` (null = company-wide) *then* — and note it is
a data migration for already-generated `leave_days`, not just a column, exactly
as the paragraph below on calendar versioning warns.

*Rejected:* a shared `hr_foundation` module that leave and attendance both
require. A module whose only purpose is to be depended on is a licence nobody
buys and a toggle that must never be off — which is what Core already is.

*Rejected:* duplicating the calendar in both modules. Two lists of public
holidays disagreeing about Eid is a support call, not a design.

Recurring dates are marked but not computed: Eid moves, and a calendar that
guesses lunar dates would be wrong every year in a way nobody notices until
payroll. `is_recurring` seeds next year's list as a draft for someone to confirm.
Asserted as built — a recurring 2026 Eid produces no 2027 row on its own.

**Open, and it leans on this section: `leave_days` records no calendar version.**
§4.1 promises days are "recomputed from the calendar when the request is
approved, and never after — a holiday added retroactively must not silently
change a settled month". The never-after half is enforceable, but nothing records
*which* calendar produced a given set of days, so "why does last March differ
from this March" has no answer in the data. Either accept that and say so, or
stamp the generating calendar on the request. Cheap now, a backfill later.

### An employee needs a leaving date

In `employees`, not in `lifecycle` — the leaving date is a fact about the
employee, and payroll must read it whether or not the lifecycle module is
licensed.

```
employees   + left_on            date, nullable
            + leaving_reason     string, nullable   (resigned|terminated|contract_end|retired|deceased)
            + notice_served_until date, nullable
```

`is_active` stays as the flag every existing query uses; `left_on` is the date it
happened. Backfill leaves `left_on` null for everyone already inactive — an
invented date would look like a fact.

### Only salary is effective-dated. Nothing else about the job is.

The sharpest gap in this plan, found late and affecting three sections above it.

`employee_settings` versions the *package* by fiscal year, and that is the only
history an employee has. Everything else about their job is a live value on
`employees` and is **overwritten in place**:

| Fact | Stored as | What is lost on change |
|---|---|---|
| `designation` | plain nullable string | when they were promoted, and to what from |
| `department` | plain nullable string | which department a past cost belongs to |
| `manager_id` | live pointer, reparented on delete | who could approve for them, at any past date |

So "who reported to whom in March" is unanswerable, and nothing records that it
was ever different.

**This is not a reporting nicety, it is load-bearing for what this plan builds.**
Leave approval routes through `manager_id` via `EmployeeAccess` (§4.1, §7). A
request approved in March by a manager who has since moved keeps its
`decided_by` — the approval survives — but the *authority* for it does not, and
there is no way to reconstruct it. Every other approval chain this plan adds
inherits the same hole.

```
employee_job_history   id, employee_id, effective_from, designation, department,
                       manager_id, employment_type, reason, recorded_by
```

**Built.** `App\Modules\Employees\Services\JobHistory` answers `managerOn()`,
`designationOn()`, `departmentOn()` and `rowOn()`. Three things the plan did not
say, all of which turned out to be load-bearing:

- **`employment_type` needed a column on `employees` too.** It was listed here
  only, so nothing could snapshot it and every row stored null — the state the
  `invoice_events` migration already refuses ("a column that never fills would be
  worse than its absence"). `employees.employment_type` now exists, admin-only
  like the other job facts, and deliberately outside
  `EmployeeChangeRequest::ALLOWED_FIELDS`: nobody promotes themselves off
  probation.
- **The writer belongs on the model, not the form.** These columns are written by
  the employee form, by an approved change request, by the CSV importer and by
  tinker; a hook on any one leaves the other three overwriting history silently,
  which is the failure this table exists to end. `Employee::booted()` records on
  `updated`, and `Employee::withoutJobHistory()` stops the projection writing a
  second row for the same change.
- **Future-dating needs a job.** A transfer agreed in August and effective in
  September must record now and apply then, so `record()` refuses to project a
  row that has not started and `employees:apply-job-changes` (daily, TenantAware)
  moves the columns on the day it does. Without that command, future-dating
  records a row that never takes effect.

Not built: any UI for the history itself. The rows accumulate from the ordinary
employee form, so the natural next step is a read-only relation manager on
`ViewEmployee`, not a resource — job history is written by a change, never typed.

The `employees` columns stay exactly as they are and become the *current* row —
denormalised on purpose, because every existing query reads them and none of them
should have to learn about history to keep working.

Three things this subsumes rather than adds to:

- **`left_on` is one more row**, with `reason = 'separation'`, rather than three
  new columns. The columns above are still worth having for the reasons given —
  payroll must read the date without the lifecycle module — but they become a
  projection of the last row rather than an independent fact.
- **Gratuity needs continuous service** (§6), which is the span from the first
  row to the last, and is not derivable from a joining date alone once
  re-employment exists.
- **`department` in cost reporting.** Attributing a past payslip to the
  department the employee was in *then* is only possible with this.

*Rejected:* versioning these on `employee_settings` alongside salary. The package
is versioned by fiscal year because tax slabs are; a promotion happens on a
Tuesday. Forcing job changes onto the fiscal-year boundary would either backdate
them to July or hold them until it.

**Where it goes: `employees`, not `lifecycle`.** Same argument as the leaving
date — payroll, leave and every approval chain read it, and none of them may
depend on a licence the company might not have.

## 4. The modules

### 4.1 `leave`

```
leave_types            id, code, label, kind, is_paid, accrual_method, days_per_year,
                       max_carry_forward, allows_half_day, requires_document,
                       min_notice_days, is_encashable, is_active, sort
leave_entitlements     id, employee_id, leave_type_id, leave_year_start, leave_year_end,
                       opening_days, accrued_days, carried_in_days
leave_adjustments      id, leave_entitlement_id, days, reason, made_by, made_at
leave_requests         id, employee_id, leave_type_id, from_date, to_date, days,
                       is_half_day, half_day_period, reason, document_path,
                       status, submitted_by, decided_by, decided_at, refusal_reason,
                       cancelled_at, cancelled_by
leave_days             id, leave_request_id, date, portion, is_paid   -- one row per calendar day taken
```

**Built.** `App\Modules\Leave`, with the schema below as specified. Four decisions
the plan left to implementation, recorded because each one is load-bearing:

- **`leave_days.is_paid` is copied from the type at generation**, not read through
  the relation. A type's `is_paid` may be corrected later, and a day already taken
  must keep the character it had when it was settled — the same reason §5 records
  the proration divisor on the payslip.
- **The generation inputs are stamped.** `leave_requests.sandwich_rule_applied`
  records whether the rule was on, so two identical Friday-plus-Monday requests six
  months apart consuming 2 and 4 days reads as a policy change rather than a bug.
  §3's open question about a calendar version is answered in the `leave_days`
  migration: accepted and stated, because the days *consumed* are fully recorded and
  a version column would only explain the absences.
- **Weekends come from `leave.weekend_days`, and it is labelled a stopgap.** They
  belong to work patterns, which `attendance` owns and which do not exist yet —
  `HolidayCalendar` deliberately refuses to answer. The generator cannot avoid the
  question, so it asks a setting; the key goes away when work patterns land.
- **Pending days are read from `leave_requests.days`, not from `leave_days`**, because
  a pending request has no day rows — they are generated once, at approval. Summing
  day rows for pending leave silently returns zero, which is how it was found.

The date columns needed `App\Models\Concerns\StoresPlainDates`: Eloquent's `date`
cast writes `Y-m-d H:i:s`, which MySQL truncates and SQLite keeps, so `firstOrNew`
on a leave-year start matched nothing and the idempotent year-open hit its own unique
key. The precedent was already here in `EmployeeJobHistory::setEffectiveFromAttribute()`.

**`leave_days` is the point of the design.** A request stores its range; the days
it actually consumed are rows, because a request spanning a weekend and a public
holiday consumes fewer days than its range implies, and payroll needs to know
*which* days fell in *which* month. Without per-day rows, a leave from 28 January
to 3 February cannot be split across two payslips and the second month is wrong.
Recomputed from the calendar when the request is approved, and never after — a
holiday added retroactively must not silently change a settled month.

**Balances are computed, never stored**, the same rule as account balances:
`opening + carried_in + accrued + adjustments − taken`. A stored balance column
drifts against `leave_days` and nothing reports the drift.

**Adjustments are rows, for the same reason.** An earlier draft had
`adjustment_days` and `adjustment_reason` as two columns on the entitlement,
which fails the argument immediately above it one level down: a goodwill grant in
March and a correction in August cannot both exist, so the second silently
overwrites the first and takes its reason with it. The balance is then a number
nobody can explain, which is exactly the state computing it was meant to avoid.
`made_by` and `made_at` matter as much as `days` — "who gave me these three days
and when" is the first question every disputed balance opens with, and it is the
one an overwritten column can never answer.

Nothing else changes: `adjustments` in the formula becomes a `sum()` over the
rows, and a company that only ever makes one adjustment sees no difference.

**Status lifecycle** copies `ExpenseClaim` exactly — `pending / approved /
refused / cancelled` with `submitted_by`, `decided_by`, `decided_at`,
`refusal_reason`. Same columns, same names, same meaning. A second vocabulary for
the same idea is how two modules end up with `rejected` and `refused`.

**The leave year is not the fiscal year, and this must be a setting.** FBR's year
runs July–June and `employee_settings` are versioned by it, so the tempting move
is to accrue leave on the same boundary. But statutory annual leave in Pakistan
accrues on completing twelve months of *service*, which is an anniversary per
employee, and many employers run a calendar leave year regardless. Three
behaviours, one company setting:

| `leave.year_basis` | Leave year runs | Fits |
|---|---|---|
| `fiscal` | 1 Jul – 30 Jun | aligns with payroll and encashment on the payslip |
| `calendar` | 1 Jan – 31 Dec | what most office policies say |
| `anniversary` | joining date + n years | closest to the statutory entitlement |

Default `calendar`, **confirmed by the company running this in production**: their
leave year is the calendar year for everybody, and balances reset on 1 January
whatever an employee's joining date. So `calendar` is not a guess about what most
policies say — it is what the one policy we can read says.

Two consequences of that answer, both of which shape the defaults:

- **Carry-forward is a company setting, built in phase 1, defaulting to off.** The
  pilot resets on 1 January and carries nothing, so *their* behaviour is the
  default — but whether unused days carry is a policy other companies will answer
  differently, and it is theirs to choose rather than ours to ship.

  That decision is what makes the storage honest. An earlier draft cut
  `carried_in_days` on this codebase's own principle — the `invoice_events`
  migration refused a `viewed` column because the feature behind it did not exist,
  *"inventing a column that never fills would be worse than its absence"*. The
  principle is about columns nothing ever writes to. A setting a company can switch
  on is something that writes to it, so the column earns its place and the balance
  formula earns its `+ carried_in` term.

  **How it works.** Two tiers, deliberately: `leave.carry_forward` is the company
  policy switch, and `leave_types.max_carry_forward` is the per-type cap, because
  annual leave carrying 5 days while casual carries none is one company's normal
  and belongs in a row rather than a second setting. At the year-end reset, for each
  type, `carried_in = min(unused_balance, max_carry_forward)` with the switch on and
  `0` with it off. A cap of 0 means that type never carries even for a company that
  does — so "which types carry" needs no extra column.

  ~~**What is deliberately still out: expiry.**~~ **Somebody asked — built**, as
  the additive column plus a job it was promised to be, changing nothing already
  here. `leave.carry_forward_expiry_months` (0 = carried days lapse with the year,
  as before; 3 on a calendar year is "lapse on 31 March") is read once, at the
  year-end roll, which stamps `carried_in_expires_on` on the new entitlement — a
  policy change in June cannot move a date a year already began with, the same
  rule as the window itself. The daily `leave:lapse-carried-days` sweep then voids
  whatever is left of the carried days (leave taken before the date spends carried
  days first) as a **leave_adjustments row** and clears the date, which is what
  makes it idempotent: `carried_in_days` is never rewritten, so what was given and
  what lapsed both stay on the record. Nothing is paid out — the year-end
  encashment run stays deliberately unbuilt, per the lapsing paragraph below.
- **A mid-year joiner is pro-rated in their first year — confirmed.**
  `days_per_year × months_remaining / 12`, rounded to the nearest half day so it
  agrees with the half-day granularity `leave_days.portion` already uses.

  `months_remaining` needs pinning down or it will be implemented three different
  ways: **count the joining month when the joining date falls on or before the
  15th, otherwise start from the following month.** So a 20 September joiner gets
  `days_per_year × 3 / 12`, and a 12 September joiner gets `4 / 12`. That
  half-month rule is the conventional one and is the shipped behaviour rather than
  a setting — a company that wants something else is asking for a different accrual
  method, not a different rounding.

  Kept as `leave.prorate_first_year`, defaulting on, because a company that grants
  the full year's days from day one is common enough to be worth one boolean, and
  both directions are tested (§10.6).

`accrual_method` per type: `annual_upfront`, `monthly_accrual`,
`semi_monthly_accrual` (a twenty-fourth on the 1st and the 16th),
`on_completion_of_service`, `unlimited` (sick leave that is not counted down),
`none` (unpaid).

**Lapsing raises "does anybody get paid for it", and there are two different
moments — do not conflate them.** At the year end, an unused balance lapses and
nothing is paid; that is the rule above. On **separation**, the current year's
unused balance may be encashed, which is `is_encashable` on the type and
`leave_encashment_days` on the final settlement (§4.6). One is a policy about a
calendar boundary, the other is money owed to somebody leaving. A year-end
encashment run that pays out days which were about to lapse is a third behaviour,
nobody has asked for it, and building it would quietly reverse the lapse rule.

**Seeded types are defaults, not law.** Ship Annual, Casual, Sick, Unpaid,
Maternity, Paternity, Bereavement, Hajj with editable day counts, and say plainly
in the help text that the statutory minima are **provincial** — Sindh, Punjab,
KP and Balochistan each legislate their own shops-and-establishments rules — and
that the seeded numbers are a starting point a company's HR must confirm. This
application already takes that position on tax slabs ("a Finance Act change is a
re-seed"); it should not pretend to more certainty about labour law than it has.

**Approval, and who cannot approve their own leave.** The default approver is the
employee's `manager_id`, resolved through `EmployeeAccess`. A manager filing
their own leave routes to *their* manager; the employee at the top of the tree
has nobody — which is exactly the dead end `SecondApproverRule` was written for
in the ledger. Reuse that shape: a `leave.require_second_approver` company
setting, default on, and when off a self-approval is *recorded as one* in the
activity log. Do not invent a second answer to a question this codebase has
already answered.

**Half days and hours.** Half days yes (`portion` = 1.0 / 0.5); hourly leave no,
because nothing else in this system measures pay in hours except
`extra_work_hours`, and a two-hour leave that cannot reduce pay is a note, not a
leave record.

**Sandwich leave — a company setting, and it belongs here rather than later.**
Taking Friday and Monday off, and the weekend between counting as leave too, is a
common policy in this market. It is absent from the design above, and it is not
an additive feature: it changes which days `leave_days` generates, which §4.1
calls the point of the design. Bolting it on afterwards means regenerating days
for requests that have already been approved and already reached a payslip.

`leave.sandwich_rule`, defaulting **off** — the pilot has not asked for it and a
policy that quietly consumes two extra days is the wrong default to impose:

| Value | A Friday + Monday request consumes |
|---|---|
| `off` | 2 days. Non-working days between are skipped, exactly as designed |
| `enclosed` | 4 days. Non-working days *between* two leave days are consumed |

`enclosed` is the only variant offered. The stricter reading some policies take —
a holiday adjacent to leave is consumed even at the edges — is deliberately not
built: it makes a single Friday's leave cost three days, which nobody expects,
and no policy anyone here has read asks for it.

The setting is read once, when the request is approved and `leave_days` are
generated, and never again — the same rule the holiday calendar already follows.
Changing the policy must not restate leave somebody has already taken.

**Compensatory off.** Working a weekly off or a public holiday earns a day back.
Not a new mechanism: it is a `leave_type` with `accrual_method = 'compensatory'`,
credited by an approved `attendance_day` whose `status` is `weekly_off` or
`holiday` and whose `worked_minutes` are non-zero. Every input already exists in
§4.2.

Two constraints that make it safe rather than a second balance nobody can
explain. It accrues **only from approved attendance**, so it cannot be
self-granted by typing a day in. And it needs an **expiry**, which is the one
place this plan admits a lapse date it refused for carry-forward: a comp-off
earned in March and taken three years later is not time off in lieu of anything.
`leave.comp_off_expiry_days`, defaulting to 90.

**When each lands, and they are not the same answer.** An earlier draft said
"both phase 2 or later", which contradicts the paragraph above it: if sandwich
changes what `leave_days` generates, deferring it means regenerating days for
requests already approved and already on a payslip.

- **Sandwich: the setting and the generation branch land in phase 1, with the
  generator**, shipped `off`. Building the branch while the generator is being
  written costs almost nothing; retrofitting it costs a data migration over
  settled months. Switching it on for a company is then a policy decision, any
  time.
- **Comp-off is phase 2 or later**, genuinely — it accrues from approved
  attendance rows, which do not exist until then.

Neither is needed for the pilot. The difference is that one of them is cheap now
and expensive later, and the other is the same price whenever it is built.

### 4.2 `attendance`

```
work_patterns          id, name, is_default, week_start, notes
work_pattern_days      id, work_pattern_id, weekday, is_working, expected_hours,
                       start_time, end_time
employee_work_patterns id, employee_id, work_pattern_id, from_date, to_date
attendance_days        id, employee_id, date, status, check_in_at, check_out_at,
                       worked_minutes, overtime_minutes, late_minutes,
                       source, note, leave_request_id
```

`attendance_days` is **one row per employee per working day** and is the only
high-volume table in this plan: 40 employees × 22 days ≈ 900 rows a month, ~11k a
year per tenant. Fine for MySQL, but it is the first table here that grows without
bound, so it is `Prunable` from the start with a retention setting, scheduled the
way `ProjectEnvironmentCheck` is (`app/Modules/Projects/routes/console.php`) —
class constant, never a string.

`status`: `present`, `absent`, `on_leave`, `holiday`, `weekly_off`, `half_day`,
`work_from_home`, `not_marked`. `source`: `manual`, `import`, `self_service`,
`device`. **`not_marked` is deliberately distinct from `absent`** — the same
distinction the health checks make between `down` and `unknown`. A month nobody
filled in must not read as everybody absent, because absent costs money.

`leave_request_id` on the day row is the link that keeps the two modules honest:
an approved leave writes `on_leave` days, and an attendance day claiming
`present` for a date the employee is on approved leave is a contradiction the
importer must refuse rather than overwrite.

**Biometric devices are out of scope**, and the module is shaped so they can be
added later: a CSV import with a documented column mapping, `source = import`,
and a duplicate-safe unique key on `(employee_id, date)`. Naming a device vendor
in a plan whose author has not seen the device is how you get a driver nobody can
test.

**Regularization: how a forgotten day gets fixed.** `not_marked` is only honest
if there is a way out of it, and without one it accumulates into precisely the
"month nobody filled in" this status exists to distinguish. The way out is not an
admin editing rows — it is the `EmployeeChangeRequest` pattern applied to a single
day, which §1 already names as the pattern every HR request should copy.

```
attendance_regularizations  id, employee_id, date, requested_status,
                            requested_check_in_at, requested_check_out_at, reason,
                            status, submitted_by, decided_by, decided_at,
                            refusal_reason
```

Same vocabulary as `leave_requests` and `ExpenseClaim`, for the reason given
there. Approved, it writes the day with `source = self_service` — the enum above
already anticipates this — and the original values stay on the request, so the
correction is auditable rather than a silent overwrite. Two refusals are
structural rather than policy: a day covered by approved leave cannot be
regularized (the same contradiction `leave_request_id` already refuses on
import), and a day inside a settled payroll month cannot either, on the same
principle as recomputing `leave_days` after approval.

Volume makes this worth building early rather than late: it is the
highest-frequency request in the family after leave itself, and the only one an
employee raises about their own past.

**Overtime** accumulates as `overtime_minutes`. **It cannot simply feed
`extra_work_hours`, and an earlier draft of this section said it could** — a
mistake worth recording, because the column name is what caused it.
`extra_work_hours` is a **rupee amount**, not a count of hours: `decimal(10,2)`,
typed by hand into `PayslipForm`, summed straight into earnings and debited as
`bonus_overtime` alongside `bonus` (`PayrollPostingService.php:66`). So the join
is minutes → money with nothing in between, and nothing in this system currently
knows what an hour of anybody's time costs.

Three things it needs, none of which exist:

| Piece | Decision |
|---|---|
| An hourly rate | Derived from the package, not stored: `basic_wage ÷ (contracted days × expected_hours)` — `work_pattern_days.expected_hours` is the only place this system records how long a working day is. Recorded on the payslip when used, the way §5 records the proration divisor, because a rate recomputed later against a changed package would restate a settled month. |
| A multiplier | `attendance.overtime_multiplier`, a company setting defaulting to **2.0**. The Factories Act mandates double the ordinary rate; other establishments differ by province, which is the same reason §6 makes every statutory figure configurable rather than encoded. |
| Caps | Daily and weekly limits **warn, never adjust**. Silently capping paid overtime hides an employer's compliance problem and underpays somebody; the minimum-wage row in §6 already takes this position. |

Until all three exist, `overtime_minutes` is a recorded fact that does not reach
pay — which is the same honest state `total_working_days` and `lop_days` are in
today (§5), and far better than a number that reaches pay by an undefined route.

### 4.3 `timesheets`

```
timesheet_entries  id, employee_id, project_id, date, minutes, is_billable,
                   description, task, approved_by, approved_at, locked_at
```

Deliberately thin, and deliberately *not* an attendance record: time against a
project is a billing and utilisation fact, and forcing it to reconcile to the
minute with `attendance_days` would make both unusable. The two are compared in a
report ("logged 6h against projects on a 8h day"), not enforced.

`project_employee` already carries dated stints with `allocation_pct`, so the
plan/actual comparison is available for free: allocation says 50%, timesheets say
20%.

**The prize is billing.** `MonthlyBillingService` bills a client per employee at
full monthly cost. A time-and-materials client is billed by hours × rate, and
that is a different bill from the same data. Add rate resolution — project rate,
else employee rate, else a company default — and a `hours` line type on the
billing run. Guarded: `billing` requires `timesheets` for that line type only,
so a headcount-billed client is unaffected.

### 4.4 `recruitment`

```
vacancies      id, code, title, department, designation, employment_type,
               openings, status, hiring_manager_employee_id, salary_min, salary_max,
               currency_code, description, opened_on, closed_on
applicants     id, name, email, phone, cnic, source, resume_path, linkedin,
               current_employer, notice_period_days, expected_salary, notes
applications   id, vacancy_id, applicant_id, stage, status, applied_on,
               rejected_reason, rating, employee_id
interviews     id, application_id, round, scheduled_at, mode, panel, location,
               outcome, notes, interviewer_employee_id
offers         id, application_id, salary, components (json), joining_date,
               status, issued_at, responded_at, decline_reason
```

`applicants` separate from `applications` because one person applies twice, and a
system that cannot see that has no memory.

**Hire is a conversion, not a copy.** Accepting an offer creates the `Employee`,
the `User` (optional — `allow_employees_without_a_login` exists), and the
`EmployeeSetting` from the offer's components, in one transaction, and stores
`applications.employee_id` so the trail from vacancy to payroll survives. Hidden
when `employees` is unlicensed.

**Applicant data is the most sensitive data this application would hold**, and it
is held about people the company never hired. So: a retention setting with a
default (24 months after rejection), a `Prunable` model that deletes the resume
file with the row, and an explicit note in the help text that the company is the
data controller. Nothing here should quietly keep a CV forever.

### 4.5 `performance`

```
review_cycles     id, name, period_start, period_end, status, self_review_due_on,
                  manager_review_due_on, calibration_notes
reviews           id, review_cycle_id, employee_id, reviewer_employee_id, status,
                  self_rating, manager_rating, final_rating, strengths,
                  improvements, submitted_at, shared_at, acknowledged_at
goals             id, employee_id, review_cycle_id, title, description, metric,
                  target, actual, weight, status, due_on
one_to_ones       id, employee_id, manager_employee_id, met_on, notes, private_notes
```

`MPR` already is a monthly self-report with month-over-month comparison. Do not
duplicate it: a review cycle *reads* the MPRs in its period as evidence, guarded
on `modules()->enabled('mpr')`. That is the whole integration and it is worth
more than another free-text box.

**Ratings do not touch pay.** No automatic increment, no formula from rating to
salary. A rating is an opinion; a package is a versioned `EmployeeSetting` that
someone approves. Wiring the first to the second would make the appraisal a
payroll instruction, and the first disagreement about a rating would become a
payroll incident. The link is a *suggested* new `EmployeeSetting` a human saves.

`private_notes` on a one-to-one is visible to the manager and above only — worth
naming explicitly, because `EmployeeAccess` grants a manager their whole downline
and an employee must not read the notes about them.

### 4.6 `lifecycle`

```
checklist_templates    id, kind, name, is_active                   -- onboarding|exit
checklist_items        id, checklist_template_id, title, owner_role, due_offset_days, sort
employee_checklists    id, employee_id, checklist_template_id, kind, started_on, completed_on
employee_checklist_items id, employee_checklist_id, checklist_item_id, assignee_employee_id,
                       due_on, completed_at, completed_by, note
employee_documents     id, employee_id, kind, number, issued_on, expires_on,
                       file_path, verified_by, verified_at
issued_assets          id, employee_id, asset_kind, description, serial_no,
                       fixed_asset_id, issued_on, returned_on, condition_note, value
final_settlements      id, employee_id, left_on, notice_recovery, leave_encashment_days,
                       leave_encashment_amount, gratuity_amount, outstanding_advance,
                       unreturned_asset_value, other_deductions, net_amount,
                       status, approved_by, payslip_id, payment_id
```

`employee_documents.expires_on` gets the reminder machinery the certificate
expiry checks already prove out in `projects` — a daily command, thresholds in
config, notifications on transitions rather than on every run.

`issued_assets.fixed_asset_id` is nullable and points at Accounting's
`fixed_assets` when the laptop is on the books; a phone that was never
capitalised has a description and no link. Guarded on `accounting`.

**Final settlement is a proposal, not a posting.** It gathers what the system
already knows — unrecovered advance balance from `advances`, encashable leave
from `leave`, gratuity from §6, unreturned assets from the checklist — and
produces a figure for approval. Paying it goes through the existing payslip or
payment path, which already posts to the ledger correctly. A second money path
that writes journal entries of its own is how a ledger stops reconciling.

### 4.7 The settings surface — what a company picks for itself

Every policy decision in this document should be a company's to make wherever it
can be, and none of it needs a new mechanism: `TenantSettings` resolves a tenant
override, falling back to `config()`, which falls back to an `env()` default. That
is exactly how `accounting.require_second_approver` works — an installation default
in `.env`, a per-company answer on the Company Settings page, and the company's
answer winning once given.

**Three tiers, and putting something in the wrong one is the mistake.**

| Tier | Lives in | Changed by | Examples |
|---|---|---|---|
| **Company setting** | `settings` table over `config/leave.php` + `env()` | Administrator, on Company Settings | leave year basis, first-year pro-rating, second approver, pro-rate pay on attendance |
| **Reference data** | rows in a table, edited in a Filament resource | HR, day to day | leave types and their day counts, `is_paid`, `min_notice_days`, `is_encashable`, holidays, work patterns |
| **Shipped behaviour** | code | us, in a release | the half-month boundary in `months_remaining`, `not_marked` ≠ `absent`, balances computed not stored |

Most of what looks like "settings" is really the middle row, and that is the better
answer: a company that wants 18 annual days edits a `leave_types` row — no
deploy, no toggle, no combination for us to support. Reach for a company setting
only when the choice cannot be expressed as data.

**The settings, concretely.** A `Leave` section on the existing Company Settings
page, in the shape that page already uses (`Section::make(…)->schema([Toggle::make(…)])`,
saved through `TenantSettings::set()`, read in `mount()` via `setting()`):

| Key | Type | Default | Question it answers |
|---|---|---|---|
| `leave.year_basis` | `calendar` / `fiscal` / `anniversary` | `calendar` | when does everyone's leave year start |
| `leave.carry_forward` | bool | **off** | do unused days carry into the next leave year, capped per type by `leave_types.max_carry_forward` |
| `leave.prorate_first_year` | bool | on | does a mid-year joiner get the full year's days |
| `leave.require_second_approver` | bool | on | may somebody approve their own leave |
| `leave.min_notice_enforced` | bool | off | is `min_notice_days` a block or a warning |
| `attendance.retention_months` | int | 36 | how long daily rows are kept before pruning |
| `payroll.prorate_on_attendance` | bool | **off** | does unpaid absence reduce pay (§5) |
| `payroll.proration_divisor` | `working_days` / `calendar_days` / `fixed_26` / `fixed_30` | `working_days` | what it divides by |

Two implementation notes that are easy to miss:

- **The section must be hidden when the module is not licensed.** Company Settings
  belongs to Core, so a company that never bought Leave would otherwise be offered
  leave policy: `Section::make('Leave')->visible(fn () => modules()->enabled('leave'))`.
- **Every save goes to the activity log.** "Who turned pro-rating on, and when" is
  where an incident about a short payslip begins, and the modules page already
  takes this position for licence changes.

**The trap: a setting that rewrites the past.** This is the part worth designing
rather than discovering. Change `leave.year_basis` from `calendar` to `fiscal` in
June and, if the leave year were derived at read time, every entitlement window
would move, every balance would restate mid-year, and approved leave would land in
a year that no longer exists.

The schema already prevents it, and it should be stated as the reason those columns
exist: **`leave_entitlements` stores `leave_year_start` and `leave_year_end` on the
row.** A setting change therefore governs entitlements created *after* it, and the
current year keeps the basis it began with. Same rule for
`leave.prorate_first_year`: it decides what a new entitlement is worth, never what
an existing one is retroactively worth. Same rule again for
`payroll.proration_divisor`, which is why §5 records the divisor on the payslip.

Stated as one rule for whoever implements any of it: **a setting decides what
happens next, never what already happened.** Where a change would restate settled
figures, it takes effect from the next leave year or the next payroll month, and
the UI says so at the point of saving.

**Why not everything.** Each boolean doubles the behaviours we support and ought to
test; eight of them is 256 combinations, and the ones nobody tries are where the
bugs live. A setting has to earn its place on three counts: a real company wants the
other value, the default is defensible without asking, and both directions are
tested.

The half-month boundary in `months_remaining` is the one thing here that fails
those counts and stays in code: nobody has asked for a different one, and a company
that did would be asking for a different accrual method rather than different
rounding.

**And a setting must be backed by something that happens.** Carry-forward was
briefly excluded on exactly this ground — a toggle over storage that did not exist
is a promise, not a setting. The answer was not to drop the toggle but to build the
feature behind it (§4.1), which is the right resolution whenever the two are in
tension: if a choice deserves to be a company's, then what it switches deserves to
work. Expiry of carried days is the remaining piece held back, and it is held back
as *scope* rather than disguised as a setting — no toggle claims it exists.

## 5. The payroll join — the decision that cannot be deferred

Today `paid_days` and `lop_days` are printed and ignored. Once `leave` and
`attendance` fill them, there are exactly two honest options, and the wrong one
is silent.

| Option | Behaviour | Consequence |
|---|---|---|
| **A. Attendance informs, payroll ignores** (today) | LOP shows on the payslip; net unchanged | a payslip that says "LOP 3 days" and pays a full month is a document nobody can defend |
| **B. Payroll pro-rates on paid days** | earnings × `paid_days / total_working_days` | correct, and it changes every existing payslip's calculation path |

**Recommendation: B, behind a company setting, defaulting to off for existing
companies and on for new ones.** The reasoning is the same as
`ACCOUNTING_REQUIRE_SECOND_APPROVER`: a behaviour that moves money must not
change under a company that did not ask for it, and a new company should get the
correct default.

**Asked and answered: the company in production docks nothing today.** An unpaid
absence costs the employee nothing there. That answer removes one risk and
introduces one product question.

The risk it removes is the worst one this section had. Nothing in the payslip form
can reduce pay for a month — `basic_wage` and `medical_allowance` are `readOnly()`
(`PayslipForm.php:145-153`), and the editable allowances are only honoured when
greater than zero (`((float) $x > 0) ? $x : $setting->…`), so they cannot be zeroed
for a single month either. Anyone docking pay today would have to have been editing
the versioned `EmployeeSetting`, and pro-rating layered on top of that practice
would dock the same absence twice. **They are not doing it, so there is nothing to
unwind** — no manual practice to stop, no double-deduction to hunt for in the first
month. Phase 3's parallel run is still worth doing; it is no longer defusing
anything.

The question it introduces is whether they want B at all. If an absence has never
cost anybody anything, the value of `leave` and `attendance` to *them* is the
record, the balances and the approvals — phases 1 and 2 — and pro-rating is a
capability for the next customer who asks for it. So it is decoupled in §9 rather
than sitting on the critical path, and the switch defaults off, which for them
means the module changes nothing about pay on the day it ships. That is the right
outcome: they asked for leave tracking, not a pay cut.

One caveat for reporting later: because absence has never been docked, any historical
`lop_days` in their data records days that were paid regardless. A future "unpaid
days" report over past months would be wrong, and should start from the date
pro-rating is switched on rather than from the beginning of the data.

`payroll.prorate_on_attendance`, and the rules it needs:

- **Which components pro-rate.** A new `pay_components.prorates` flag, because
  `is_taxable` proves the pattern — a component already knows things about
  itself. Basic wage and allowances usually pro-rate; a fixed medical allowance
  or a device allowance often does not; a deduction never does by attendance.
- **Pro-rate the components, never the totals — and this is measured, not
  assumed.** Scaling `basic_wage`, `total_earnings` and `net_salary` by
  `paid_days / total_working_days` after the calculation is the obvious
  shortcut, and it does not merely mis-state the payslip: the payroll journal
  entry stops balancing, and payslip *creation* throws mid-run —
  `InvalidArgumentException: Entry is not balanced: debits 211227.27 !=
  credits 208626.82` from `JournalEntryService.php:348`, because
  `PayrollPostingService` books debits from the earning figures and credits from
  the deductions and net payable, and scaling one side leaves the other where it
  was. Verified by deliberately mutating `Payslip::booted()` and running
  `PayslipAttendanceProrationTest`. Pro-rating therefore belongs where the
  posting reads its numbers — the component amounts inside
  `PayslipService::calculateByParams()` — and a "quick fix" applied to the
  totals will be discovered by the ledger rather than by a reviewer.
- **The divisor is a decision, not an accident.** Calendar days, working days per
  the work pattern, or a fixed 26/30. Company setting, defaulted to working days
  from the work pattern, because that is the number `attendance` can actually
  produce. Store the divisor used on the payslip — a package recomputed next year
  under a changed setting must not silently restate a settled month.
- **`total_working_days = 0` means "not known"** and must pro-rate nothing, or
  every payslip raised by `MonthlyPayrollService` before attendance exists divides
  by zero and pays nobody.
- **Tax follows the reduced gross**, which the existing calculator handles because
  it works off the components — but `AnnualTax` projections read a monthly figure
  and will now see a dip. Verify the projection does not extrapolate one LOP month
  across the year.
- **A locked `PayrollRun` is closed.** Leave approved after sign-off adjusts the
  *next* month, and the leave record says which month it was settled in. Silently
  reopening a signed-off run is worse than a late adjustment.

Write the guarded read the way Payroll already reads Advances and Expenses:
`modules()->enabled('leave')` / `('attendance')`, falling back to the hand-typed
column when off. Payroll stays sellable without either.

**Today's behaviour is pinned by two tests**, because pro-rating can be got wrong
in two independent ways and each one is invisible to the other:

| Test | Fails when | Says |
|---|---|---|
| `PayslipAttendanceProrationTest` | the calculation is *given* an attendance figure — i.e. pro-rating is introduced at all | the switch must default off for companies already running payroll; `total_working_days = 0` must pro-rate nothing |
| `PayslipCalculationSeamTest` | a figure is adjusted *after* the calculation — i.e. pro-rating is put in the wrong place | pro-rating belongs inside `calculateByParams()`, on the amounts the posting reads |

Both are characterisation tests with the instructions in their docblocks, and both
were verified to fail by deliberately mutating `Payslip::booted()` — which is also
how the fixture flaw in the second one was found: with `paid_days` equal to
`total_working_days`, an attendance-proportional adjustment is a multiplication by
one, and the test went green over the exact mistake it exists to catch. Its
fixtures now carry real lost days.

Doing this correctly fails the first test and passes the second. That is the
intended signal, not a nuisance.

## 6. Statutory Pakistan HR — where each piece lands

None of this is a new module; it is Payroll work, and it is the part that needs a
maintainer's attention every provincial amendment, exactly as the README says of
the Finance Act.

| Scheme | Shape | Lands in |
|---|---|---|
| **EOBI** | employer + employee contribution on minimum wage, monthly return | `pay_components` (deduction + employer cost), a monthly export like the FBR file |
| **Provincial social security** (SESSI/PESSI/…) | employer contribution up to a wage ceiling | same, per-province rate in config |
| **Provident fund** | employee % + employer match, a liability that accrues and is paid out | components + a ledger liability account; balance per employee |
| **Gratuity** | typically one month's wage per completed year, on separation | computed at settlement (§4.6), provisioned monthly if the company wants the liability on the books |
| **ESI / health insurance** | already a column and a component | `esi_health_insurance` exists |
| **Minimum wage** | a floor to warn against, per province and per year | a validation warning on `EmployeeSetting`, never a silent adjustment |
| **Workers Welfare Fund / WPPF** | company-level, profit-based | Accounting, not payroll |
| **Maternity / paternity leave** | statutory paid leave, provincially set | `leave_types` with `is_paid` and a day count |

Rates and ceilings go in `config/statutory.php` keyed by fiscal year and
province, the way `salary_slabs` are keyed by fiscal year — so an amendment is a
re-seed, not a code change. Every figure shipped is a default with a note that it
must be confirmed; a wrong contribution rate applied confidently is worse than a
blank one that asks.

## 6a. Where AI may and may not act

Nothing here is built, and this section exists to fix the position before
somebody builds it without one. Resume ranking and interview-note summarization
are now baseline in competing products, so the question is not whether it gets
proposed but what it is allowed to touch when it does.

The rule is one this plan family has already reached three times independently —
`crms §10` states it plainly (*"intentions must not write to the record without a
person in between"*), §4.5 refuses ratings-to-pay automation, and `crms §3`
refuses commission-to-payroll. Three sections agreeing makes it the house rule
rather than a per-feature judgement, so it applies here unchanged:

| Allowed | Refused |
|---|---|
| Rank or shortlist applicants against a job's criteria | Reject an applicant, or move a `recruitment` stage |
| Draft a summary of interview notes for a human to save | Write to `applicants` or an evaluation without a person saving it |
| Suggest a leave-policy anomaly worth a look | Decide a `leave_request`, or adjust a balance |
| Draft the text of a settlement letter | Compute or post a final settlement (§4.6) |

Two constraints beyond the rule. **Nothing goes to a third party without the
company switching it on** — CVs, salaries and medical-leave reasons are the most
sensitive data in this application, and a per-company setting defaulting to off
is the same shape `campaigns` uses for WhatsApp. And **a suggestion is never
stored as a fact**: if a ranking is persisted it carries its model and timestamp
and is displayed as a suggestion, because an unlabelled score in a table becomes
a decision the moment somebody sorts by it.

## 7. Access control

`EmployeeAccess` already answers "whose data may this user see" — own record plus
transitive downline, with `Administrator / Accountant / Manager / CEO`
privileged. Every new resource scopes through it, including the option lists of
filters and selects, or a manager finds other people's names in a dropdown.

Three additions:

1. **An approver is not a viewer.** A manager approving leave needs the request
   and the balance, not the medical certificate on it. `requires_document`
   attachments are visible to the approver and HR, and that is a policy decision
   worth writing down rather than an oversight.
2. **`private_notes` on one-to-ones and interview feedback** are manager-and-above
   only, not downline-wide.
3. **Roles.** New permission groups per module (`Leave`, `LeaveType`,
   `Attendance`, `Timesheet`, `Vacancy`, `Applicant`, `Review`, `Checklist`,
   `EmployeeDocument`, `Settlement`), and the seeded roles get: Employee — own
   requests and timesheets; Manager — approve downline; CEO/Administrator —
   everything. Watch both permission traps in the checklist, §6.

## 8. Notifications

Everything needed exists: `EmailTemplate` + `TemplatedMail` for per-company
wording, `App\Support\WhatsApp` senders (the right channel for a leave approval in
Pakistan), and the landlord `notifications` table.

Template keys to add: `leave.submitted`, `leave.approved`, `leave.refused`,
`leave.cancelled`, `attendance.missing`, `timesheet.due`, `document.expiring`,
`interview.scheduled`, `offer.issued`, `review.due`, `checklist.item_due`.

One rule, learned from the health-check alerts: notify on **transitions**, never
on every scheduled run. A daily "document expiring" job that mails the same
person the same warning for thirty days trains them to filter it.

## 9. Phasing

Leave first because it is the most-asked-for and the least entangled; the payroll
join last within each phase, because it is the only part that moves money.

| Phase | Work | Risk |
|---|---|---|
| **0** | **BUILT.** `holidays` in Core; `left_on` + `leaving_reason` and **`employee_job_history`** on `employees` (§3); `docs/new-module-checklist.md` walked once end to end | none |
| **1** | **BUILT.** `leave`: types, entitlements, **adjustments as rows**, requests, `leave_days` **including the sandwich branch, shipped off**, computed balances, the year-end roll with carry-forward behind its setting, approval with the self-approval setting, employee self-service | low |
| **2** | **BUILT.** `attendance`: work patterns, daily rows, CSV import with a real dry run, `not_marked` handling, **regularization requests**, pruning | low |
| **2a** | **BUILT.** Compensatory off: recomputed daily from qualifying attendance inside the expiry window, so it is idempotent and expires without anybody touching it | low |
| **3** *(off the critical path — see below)* | **BUILT, shipped OFF.** The payroll join — `payroll.prorate_on_attendance`, `pay_components.prorates`, the divisor recorded on each payslip | **high — money** |
| **3a** | **BUILT, shipped OFF.** Overtime reaches pay — the derived hourly rate and multiplier recorded on the payslip, caps that warn. Independent of phase 3, and not scaled by it: overtime *adds* pay where pro-rating *removes* it | **high — money** |
| **4** | **BUILT.** `timesheets` + the hours-based billing line. Time with no rate is named and not billed, never billed at a guess | medium |
| **5** | **BUILT.** `lifecycle`: checklists (items copied, not joined), documents warning once per threshold, issued assets | low |
| **6** | **BUILT.** Final settlement — a proposal that posts nothing; paying goes through the existing payslip or payment path | medium — money |
| **7** | **BUILT.** `recruitment` incl. the all-or-nothing hire conversion and applicant pruning that deletes the CV with the row | low |
| **8** | **BUILT.** `performance`, reading MPR as evidence. Ratings reach no payslip; the increment is a suggestion | low |
| **9** | **BUILT.** Statutory schemes: EOBI (on the minimum wage, not on pay), provincial social security with its ceiling, PF off by default, gratuity at settlement, minimum wage as a warning. Figures computed for a person to enter — nothing applied | **high — money and law** |

Phases 1, 2, 4, 5, 7 and 8 are independent of each other and can ship in any
order. 6, 9 and **3a** need a payroll month run in parallel against the old
figures before anyone trusts them.

**Phase 0 carries more than it looks.** `employee_job_history` is the one item
here that everything else quietly assumes: leave approval routes through
`manager_id`, final settlement needs continuous service, and department cost
reporting needs to know which department someone was in at the time. It is
cheap while `employees` is small and irreversible-in-practice once a year of
promotions has been overwritten, which is why it is phase 0 rather than filed
with `lifecycle`.

**Phase 3 is now optional, and that is a change.** It was written as the phase that
made 1 and 2 worth having; the company in production docks nothing for unpaid
absence today (§5), so for them leave and attendance deliver their whole value
without it, and switching it on would be a new deduction rather than a
correction. It stays fully specified because the next customer will ask — but it
should be built when somebody asks, not because it is numbered 3. Phases 4 to 8 do
not depend on it, and phase 6 needs leave *balances* rather than pro-rated pay.

## 10. Testing

Beyond the eight `Module*` tests every module must satisfy:

1. A leave request spanning a weekend and a public holiday consumes the right
   `leave_days` and no more.
2. A request spanning a month boundary splits across two payslips.
3. Balance = opening + carried_in + accrued + adjustments − taken, asserted against
   `leave_days` rather than a stored column.
4. **Carry-forward, both directions, because it is a setting** (§4.1):
   - off (the shipped default): an unused balance is gone on 1 January,
     `carried_in_days` is 0, and a request dated after the reset draws on the new
     year;
   - on: `carried_in = min(unused, leave_types.max_carry_forward)` — five unused
     days against a cap of three carries three, and two are lost;
   - a type with `max_carry_forward = 0` carries nothing even with the switch on,
     which is how "annual carries, casual does not" is expressed;
   - **switching the setting on in June does not resurrect days that already
     lapsed** at the last reset. This is §10.8's rule applied to the setting most
     likely to break it — a carry job that recomputes history would hand people
     back leave the company had already written off.
5. **The leave year is the same for everybody**: two employees with different
   joining dates share `leave_year_start` and `leave_year_end`, which is what
   distinguishes `calendar` from the `anniversary` basis the setting also offers.
6. A mid-year joiner's first-year entitlement follows `leave.prorate_first_year`:
   `days_per_year × months_remaining / 12` with it on, the full year's days with it
   off. Both directions asserted, plus the half-month boundary — joining on the
   15th counts that month, the 16th does not.
7. **A paid leave request never writes `lop_days`, and an unpaid one never writes
   `leaves_taken`.** The two columns meant the same thing for as long as nothing
   read them (§11); this is what keeps them apart now that something does.
   **Built and tested** — `PayrollLeaveColumnsTest`, enforced in `AttendanceFigures`.
   Note the `is_paid` recorded on the *day* decides, not the type as it reads today,
   so correcting a type cannot restate a settled month.
8. **A setting decides what happens next, never what already happened** (§4.7) —
   one case per setting that could restate history:
   - changing `leave.year_basis` mid-year leaves every existing entitlement's
     `leave_year_start` / `leave_year_end` exactly where it was;
   - changing `leave.prorate_first_year` does not restate an entitlement already
     granted;
   - changing `payroll.proration_divisor` does not restate a payslip that recorded
     a different one.
9. **Settings fall back the way `accounting.require_second_approver` does**: no
   tenant override → the `config()`/`env()` installation default; a saved company
   answer wins and keeps winning when the installation default later changes. The
   shape is already tested in `SecondApproverRuleTest` — copy it rather than
   inventing a second resolution order.
10. **The Leave section is absent from Company Settings when the module is not
    licensed**, and present when it is. Core owns that page, so nothing else
    stops it offering leave policy to a company that never bought Leave.
    **Built and tested** — `HrSettingsAndRetentionTest`, asserted through the rendered
    page rather than the schema, because `visible()` is evaluated at render and a
    schema assertion would pass while the field still appeared. The same test covers
    the pay-policy section, which needs *both* `attendance` and `payroll`, and that a
    save with the section hidden writes no leave policy at all.
11. A manager cannot approve their own leave with the setting on; can with it off,
    and the activity log records the self-approval.
12. An attendance import cannot mark `present` a day covered by approved leave.
13. A month with no attendance rows reads `not_marked`, never `absent`.
13a. **Adjustments are rows and none of them is lost** (§4.1): two adjustments in
    one leave year both exist, both reasons survive, and the balance is their sum
    — the assertion the single `adjustment_days` column could not have passed.
13b. **Sandwich, both directions, because it is a setting** (§4.1): a Friday +
    Monday request consumes 2 days with the rule off and 4 with it on; and
    **switching the rule on does not restate a request already approved**, which
    is §10.8 applied to the setting that regenerates `leave_days`.
13c. **Comp-off accrues only from approved attendance** — a `weekly_off` day with
    worked minutes credits a day, a day typed in by hand does not, and a credit
    older than `leave.comp_off_expiry_days` is not spendable.
13d. **Regularization refuses what it must** (§4.2): a day covered by approved
    leave, and a day inside a settled payroll month. Approved, it writes the day
    with `source = self_service` and the original values stay on the request.
13e. **A job change is a row, not an overwrite** (§3): changing a manager leaves
    the previous row intact with its own `effective_from`, and "who was this
    employee's manager on date X" answers correctly for a date before the change.
    Without this, an approval's authority is unreconstructable.
14. **Pro-rating off: net is identical to today's**, for a fixture payslip with
    `lop_days > 0`. Already written and passing —
    `PayslipAttendanceProrationTest` and `PayslipCalculationSeamTest` (§5).
15. Pro-rating on: earnings scale by the recorded divisor; non-prorating components
    do not; `total_working_days = 0` pro-rates nothing.
15a. **Overtime reaches pay only through the rate** (§4.2, phase 3a): with the
    multiplier at 2.0 an hour of overtime pays twice the derived hourly rate; the
    rate used is recorded on the payslip and a later package change does not
    restate it; and a cap **warns without reducing** the amount — silently
    capping would hide a compliance problem and underpay somebody.
15b. **`extra_work_hours` stays a rupee amount.** A regression test on the column
    itself, because its name says otherwise and one draft of this plan already
    read it as hours.
16. Tax and `AnnualTax` follow a pro-rated gross without extrapolating the dip.
17. Leave approved after a `PayrollRun` lock lands in the next month.
    **Built and tested** — and it needed a column to be keepable at all. §5's promise
    that "the leave record says which month it was settled in" had nothing saying it,
    which left only two possible behaviours and both wrong: count the day in its own
    locked month, where the figure is simply lost; or count it in the current month
    every time figures are pulled, so it is counted again for ever.
    `leave_days.settled_payslip_id` fixes that — a day is counted exactly once, by the
    payslip that counted it, and an unsettled day belonging to a *closed* month carries
    to the next open one. A day in an earlier **open** month is deliberately left
    alone, because that month's payslip can still take it. Reading the figures settles
    nothing; only writing a payslip does. `PayrollLeaveColumnsTest`.
18. Payroll with `leave` and `attendance` unlicensed behaves exactly as today
    (`ModuleDegradationTest`).
19. Offer acceptance creates employee + setting + optional user in one
    transaction, and rolls all of it back on failure.
20. Applicant pruning deletes the resume file, not just the row.
    **Built and tested** — `HrSettingsAndRetentionTest`. A pruned row that left its
    file on disk would mean the company still holds a stranger's CV while believing it
    does not, which is worse than not pruning. The clock runs from the **rejection**,
    not the row's age, so somebody still in a process or already hired is never pruned
    however long ago they applied; and the window is a company setting, because a
    company entitled to keep less is entitled to say so.
21. An employee cannot read `private_notes` about themselves.

## 11. Risks and open questions

- **Pro-rating is still the riskiest thing here, and it is no longer on the
  path.** Everything else adds tables; phase 3 changes the number on the payslip,
  so it keeps its parallel run, its per-company opt-in and the divisor recorded on
  the payslip. What changed is that the pilot docks nothing today (§5), which both
  removes the double-deduction risk and removes the reason to build it early. Two
  tests hold the current behaviour in place until somebody deliberately asks
  (`PayslipAttendanceProrationTest`, `PayslipCalculationSeamTest`) — **10 tests,
  110 assertions, verified passing**.

  ~~**They are untracked in git.**~~ **Settled** — both were committed in
  `b6b78b7`, so a clean checkout runs them. The warning is kept because the
  reasoning still applies to the next guard somebody writes: a characterisation
  test that exists only on one machine is the same thing as not having it.
- ~~**`leaves_taken` vs `lop_days` overlap.**~~ **Settled** — the company in
  production asked for the ordinary convention, so that is what these mean from
  here on:

  | Column | Means | Effect on pay |
  |---|---|---|
  | `leaves_taken` | approved **paid** leave days consumed in the month | none — the day is paid |
  | `lop_days` | **unpaid** absence: loss of pay | the only column pro-rating may ever read |
  | `paid_days` | working days actually paid = `total_working_days − lop_days` | the numerator of the divisor |

  Two things follow. `leave` writes `leaves_taken` from approved paid
  `leave_days` and `lop_days` from unpaid ones, so a request against an unpaid
  leave type is the only kind that can reduce pay. And **the definition belongs
  where the clerk typing it can see it** — helper text on the four form fields and
  a legend on the PDF's attendance box — because these columns spent their whole
  life meaning whatever the person filling them in assumed, and a convention that
  lives only in this document will be re-invented by the next person to open the
  form.
- **Attendance volume is the first unbounded table** in this schema. Prunable
  from day one, or a five-year-old tenant carries 50k+ rows nobody reads.
- **Provincial law is not one law.** Leave minima, social security rates and
  ceilings differ by province. Ship configurable defaults and say so; do not
  encode Sindh's rules as "Pakistan".
- **Approver chains beyond one level.** This plan approves at `manager_id` and
  falls back to the second-approver setting. Multi-step approval (manager → HR →
  CEO) was a real request and not designed here; **built 2026-10-26** as
  `leave.approval_chain` (a list of roles after the first approval) with the
  steps in a `leave_approvals` table, not a column — `decided_by` stays the one
  decision. `LeaveApprovalChain` is three questions, not an engine; `LeaveApprovalChainTest`.

  **Do not build a generic approval engine for it.** The instinct is to
  consolidate, since `EmployeeChangeRequest`, `expenses`, `advances` and
  `journal_entries` each carry their own approver logic — but they *disagree*,
  not merely duplicate: `rejected` vs `refused`, `reviewed_by` vs `decided_by`
  vs `approved_by`, and `advances` has no approval at all. Reconciling four
  vocabularies across four working modules would add to
  `ModuleBoundaryTest::KNOWN_COUPLINGS`, not shorten it. §4.1 already takes the
  cheap half of the win by copying `ExpenseClaim`'s vocabulary verbatim; the
  reusable asset is `SecondApproverRule`, and it is worth generalising when
  `leave` becomes its second caller, not before.
- ~~**Half-day payroll interaction.**~~ **Settled** — a half day of LOP is 0.5 in
  `lop_days`, which the divisor always handled, and the payslip PDF now prints the
  four attendance figures at the half-day granularity the columns hold: 0.5 shows
  as 0.5 and whole days stay whole (`pdfs/payslip.blade.php`; held by
  `PayslipPdfFreshnessTest::test_half_day_lop_prints_as_a_half_day`).
- ~~**Mobile/API.**~~ **Settled** — `GET /api/my-leave-balances` and
  `POST /api/my-leave-requests` now sit beside `/api/my-payslips`, on the same
  stack (Sanctum, company from membership, `module:leave`) and scoped the same
  way: the caller's own employee record, nobody else's. The balance carries every
  term of the arithmetic, not just the total; filing goes through
  `LeaveRequestService::submit()`, so the API cannot file what the form would
  refuse (`LeaveApiTest`). The one stated gap closed 2026-10-26 — `POST /api/my-leave-requests` takes a multipart `document`, stored where the form stores it. Before that: a type that requires a document is
  refused over JSON until the API accepts an upload.
