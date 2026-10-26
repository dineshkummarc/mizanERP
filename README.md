# Mizan

**Multi-tenant HR, payroll and double-entry accounting for Pakistani businesses.**
Built with Laravel 13, Filament 5 and PHP 8.4.

Most open-source accounting software assumes a US or EU tax and banking model. Mizan
is built the other way round: FBR salaried tax slabs stored per fiscal year, the SBP
bank directory with IMD codes for IBFT, and a Standard Chartered *iPayments* bulk
payment file that a bank will actually accept. Payroll posts straight into a real
double-entry ledger, so the trial balance is a consequence of running payroll rather
than a separate bookkeeping exercise.

> **Project status: works in production for one company, but young.** It runs the
> payroll and books of the company that built it. The API is not versioned, there is
> no upgrade path between releases yet, and the Pakistan-specific pieces (tax slabs,
> bank file format) need a maintainer's attention every Finance Act. Read
> [Security model](#security-model) before deploying it for anyone else.

---

## Screenshots

All data shown is the seeded demo dataset (`php artisan db:seed`), captured in a Demo
Company tenant.

![Account register](docs/screenshots/account-register.png)
*The account register — GnuCash-style single-screen entry with a Transfer column and running balance.*

| | |
|---|---|
| ![Dashboard](docs/screenshots/dashboard.png) | ![Reports hub](docs/screenshots/reports-hub.png) |
| *Dashboard: KPI tiles, revenue vs. expenses, and a natural-language transaction bar.* | *The reports hub — 56 reports from financial statements to FBR statutory files.* |
| ![Payslip](docs/screenshots/payslip.png) | ![Invoices](docs/screenshots/invoices.png) |
| *A payslip with earnings, deductions and the employee acknowledgement flow.* | *Sales and purchase invoices with per-row payment status.* |

---

## Contents

- [Screenshots](#screenshots)
- [Features](#features)
- [Stack](#stack)
- [Multi-tenancy](#multi-tenancy)
- [Getting started](#getting-started)
- [Testing](#testing)
- [Security model](#security-model)
- [Localisation and scope](#localisation-and-scope)
- [Contributing](#contributing)
- [License](#license)

---

## Features

### Payroll and HR
- **Employees** — profiles, designation/department, NIC with scans, dual email
  (company login + personal), bank details, reporting lines. Managers see their own
  downline only.
- **Payslips** — allowances (basic, medical, petrol, device, bonus, overtime) and
  deductions (withholding tax, advances, meals, ESI), PDF export, employee
  acknowledge/reject with comment threads.
- **Pakistan income tax** — FBR salaried slabs stored **per fiscal year**, so a
  Finance Act change is a re-seed, not a code change. Handles the 10% medical
  allowance exemption.
- **Annual tax projections** — year-to-date and projected annual liability per
  employee, recalculated on every payslip change.
- **Self-service with approval** — an employee editing their own profile files an
  `EmployeeChangeRequest` for review instead of writing to the record.
- **Monthly progress reports (MPR)** — self-service reports with PDF export and
  month-over-month comparison.

### Double-entry accounting
- **Chart of accounts** — hierarchical, normal-balance aware, contra accounts,
  roll-up balances. Balances are **computed, never stored**, so they cannot drift.
- **Journal entries** — draft → pending → approved → posted, with segregation of
  duties (an approver cannot be the creator), balanced-entry validation, and
  reversing entries instead of un-posting.
- **Payroll → ledger** — payslips book their own journal entries; regenerating one
  reverses cleanly.
- **Reports** — trial balance, profit & loss, general ledger, account register
  (GnuCash-style single-screen entry with a Transfer column and running balance).
- **Fixed assets** — straight-line and declining-balance depreciation, disposal with
  gain/loss booking.
- **Bank reconciliation** — statement import, auto-matching on amount and date
  window, manual match/exclude, completion gated on a balanced statement.
- **GnuCash import** — migrate existing books from CSV exports, with dry-run preview
  and idempotent re-imports.
- **Immutable audit log** — every domain change recorded via
  `spatie/laravel-activitylog`, with per-record history.

### Payments and banking
- **Bank directory** — Pakistani banks with IMD codes for IBFT.
- **Payments** — unified instructions for employees or beneficiaries, with payment
  type resolved per transaction (manual override → RTGS ≥ 1M → same-bank BT →
  beneficiary default → IBFT) and a draft → approved → exported → paid lifecycle.
- **Bank payment file** — a single Standard Chartered iPayments CSV (H/P/T rows, 204
  columns) mixing salaries, rent and vendor payments, with preview and
  missing-detail warnings.
- **Petty cash book** — a classic imprest book: float top-ups, vouchers analysed
  into per-category columns with image/PDF receipts, c/d balance, and month-end
  replenishment paid to the custodian through the bank file.

### Projects and environment monitoring
- **Projects** — code, status, dates, a primary and secondary manager, and a team of
  dated assignment stints (ending an assignment preserves the history).
- **Environments** — Prod/Qual/Dev per project with URL, shared login details and
  notes.
- **Health checks** — scheduled per-environment pings with uptime history, latency
  charts, content assertions ("body must contain OK"), TLS expiry warnings, and
  outage alerts with flap suppression (alerts fire on confirmed state transitions,
  never on a single failed check).
- **Public status page** — optional, token-gated, publishing only status and uptime.

### Platform
- **Multi-company** with a database per tenant (see [Multi-tenancy](#multi-tenancy)).
- **Role-based access** on every resource via `spatie/laravel-permission`, with
  per-company role scoping (teams).
- **Saved table views**, a ⌘K command palette, and user-defined custom fields on
  most records — no migration needed to add a field.
- **REST API** (Laravel Sanctum) for profile, payslips, MPRs, accounts and reports.

### Default roles

| Role | Can |
|---|---|
| **Employee** | Own payslips, MPRs and comments; read/edit projects |
| **Accountant** | Record entries, manage accounts/banks/payments/petty cash — **cannot** approve or post |
| **Manager** | Accountant + approve/reject/post/reverse, depreciation, petty cash replenishment |
| **CEO** | Manager + deletions (accounts, assets, contacts…) — but **not** journal entries |
| **Administrator** | Everything, including deleting a posted ledger transaction |

## Stack

| | |
|---|---|
| PHP | 8.4+ |
| Framework | Laravel 13 |
| Admin UI | Filament 5 (Livewire 3) |
| Database | MySQL 8 (landlord + one database per tenant) |
| Auth/roles | spatie/laravel-permission 6 (teams mode) |
| Tenancy | spatie/laravel-multitenancy 4 |
| Audit | spatie/laravel-activitylog |
| PDFs | spatie/laravel-pdf (Browsershot/Chromium) with a dompdf fallback |

## Multi-tenancy

Each company gets **its own database**. The landlord database holds users,
companies, roles and permissions; everything else — employees, payslips, ledger,
projects — lives in the tenant database. A tenant is made current by the panel URL
(`/admin/{company-slug}`), which also switches the filesystem disk roots and the
permission team id.

The practical consequence is that **tenant migrations need their path and connection
passed explicitly**, because they are not auto-loaded outside the test suite:

```bash
php artisan tenants:migrate            # correct path + connection, every company
php artisan tenants:migrate --status   # what's pending, changes nothing
php artisan tenants:migrate --pretend  # print the SQL only
```

Do not reach for `php artisan tenants:artisan migrate` — with no `--path` it migrates
the landlord folder and cheerfully reports "Nothing to migrate" while the tenant
schema stays behind. `tenants:migrate` exists to make that mistake impossible, and
refuses to run at all if no dedicated tenant connection is configured (which would
otherwise apply the tenant schema to the landlord database).

## Getting started

Requirements: PHP 8.4, Composer, MySQL 8, Node 22+ (asset build and PDF rendering —
an older node fails `npm run build` with a cryptic `styleText` SyntaxError, and after
upgrading node you must delete `node_modules` and reinstall).

```bash
git clone https://github.com/<you>/mizan.git && cd mizan

cp .env.example .env              # BEFORE composer install — artisan boots on install
# then edit .env:
#  - DB_CONNECTION=mysql plus the DB_* credentials — the landlord database
#    (say yes when `migrate` offers to create it)
#  - TENANT_DB_DRIVER=mysql plus the TENANT_DB_* credentials — tenant
#    databases are created for you, named tenant_<slug>
#  - BACKUP_NOTIFICATION_EMAIL: set a real address or delete the line;
#    left empty, `composer install` fails with "is not a valid email address"
#  - NODE_BINARY, NPM_BINARY (absolute paths, for PDF rendering)

composer install
php artisan key:generate
npm install && npm run build

php artisan migrate --seed        # landlord schema + a demo company with demo data
php artisan companies:create "Acme Ltd" --owner=admin@example.test
php artisan serve
```

`--owner` must be an existing user — `admin@example.test` is the super admin the
seeder just created. Then sign in at `/admin` and pick your company.

The demo seeder creates a super admin (`admin@example.test` / `password`) — change it
before exposing anything. Two background processes are needed for the monitoring
features to do anything:

```bash
php artisan queue:work        # jobs: health checks, notifications, PDFs
* * * * * php artisan schedule:run   # cron entry
```

Notable configuration: `config/ipayments.php` (bank file), `config/petty_cash.php`
(imprest float), `config/projects.php` (health-check intervals, alert thresholds,
retention), `config/custom_fields.php`.

## Testing

```bash
php artisan test              # full suite, about half an hour
php artisan test --parallel   # the same suite across every core, a few minutes
./vendor/bin/pint             # code style
```

The suite runs against a single SQLite database in memory: tenant migrations are
auto-loaded in the testing environment so the landlord/tenant split collapses to one
schema. That keeps tests fast, with one honest limitation — **anything that depends
on a real per-tenant connection cannot be covered**, including Spatie's per-company
command fan-out. Those paths are verified by hand.

## Security model

Read this before running Mizan for anyone but yourself.

- **Segregation of duties is enforced in policies**, not just in the UI: approvers
  cannot approve their own journal entries, accountants cannot post, and posted
  entries are reversed rather than edited.
- **Employee self-service writes are proxied** through an approval queue, so an
  employee cannot silently change their own bank account.
- **Audit log is append-only** and excludes credential fields by design.
- **Project environment passwords are stored in plain text, by deliberate decision.**
  They are shared team credentials for dev/qual/prod URLs, and the point of the
  feature is quick copy-paste. The consequence: a database dump exposes them, and
  every user holding `ProjectView` can read production logins. If you need this
  tightened, `docs/projects-listing-plan.md` §4 documents the upgrade path — an
  `encrypted` cast plus a permission gating the reveal, roughly one migration.
- **The health checker fetches operator-supplied URLs**, which are intentionally
  often internal. It is bounded to `HEAD`/`GET`, follows no redirects, stores no
  response body, and on-demand checks require their own permission — but it can
  still be used to probe whether an arbitrary host answers.
- **The public status page is off by default**, token-gated, and publishes only
  status and uptime: never URLs, credentials or error text.

Found something? See [SECURITY.md](SECURITY.md) — please don't open a public issue.

## Localisation and scope

Mizan is deliberately Pakistan-first, which is both its value and its limit:

- Income tax uses **FBR salaried slabs** and needs re-seeding after each Finance Act.
- The bank payment file targets **Standard Chartered iPayments**; other banks need a
  new exporter (the interface is there, the formats are not).
- The bank directory and payment-type rules follow **1LINK/IBFT** conventions.
- Currency formatting assumes **PKR**.

The ledger, payroll engine, approval workflows, tenancy and project monitoring are
country-agnostic. Contributions that generalise the tax and bank-file layers behind
interfaces are especially welcome — see [Contributing](#contributing).

## Contributing

Issues and pull requests are welcome. Please read
[CONTRIBUTING.md](CONTRIBUTING.md) first; in short:

- Run `php artisan test` and `./vendor/bin/pint` before opening a PR.
- New behaviour needs a test. Accounting and tax changes need a test that would fail
  without them.
- Explain *why* in the PR description. Domain rules here often look arbitrary until
  you know the regulation behind them.

## License

[MIT](LICENSE).
