# Security audit — white-box review, 2026-10-26

> **Remediation, 2026-10-26.** All fourteen findings are fixed in commit that follows this note; the
> critical/high/medium items carry new tests (`ApiAuthTest`, `ImpersonationTest`, `SecurityHardeningTest`,
> `SafeUrlTest`). The cross-tenant takeover was closed at the impersonation boundary (a non-super-admin
> cannot impersonate a user who belongs to a company they do not), which keeps the fiduciary-invite feature
> working. Full suite green: 5,181 passed.


A white-box review of the Mizan codebase at commit `aa77526a`, run as an authorized
audit of the owner's own application. Every finding below was read in the source and
traced end to end; candidate issues that turned out to be safe are listed at the end so
the absence is on the record, not merely unmentioned.

No exploit code is included. Findings describe the class of issue, the location, who can
reach it, and the one- or two-line fix.

## Severity summary

| # | Severity | Finding | Root cause |
|---|---|---|---|
| 1 | **Critical** | Cross-tenant account takeover via invite-then-impersonate | `CompanyInvitations::invite()` attaches an existing account with no consent |
| 2 | **High** | Remote code execution through the programme importer | `Blade::render()` on attacker-controlled text |
| 3 | **High** | API login issues a full token without the second factor | `Api\AuthController::login()` never checks MFA |
| 4 | **Medium** | SSRF and internal port-scan via a project environment URL | No host allowlist before the server fetches it |
| 5 | **Medium** | Stored XSS via SVG upload | `->image()` accepts `image/svg+xml`, served inline, no CSP |
| 6 | **Medium** | Tenant file route accepts a token without the `api` ability | `RequireTokenAbility` is on the `api` group only |
| 7 | **Medium** | MPR performance-report PDF has a guessable filename | `time()` in the name, membership-only file access |
| 8 | **Medium** | Isolation collapses silently if the tenant connection is unset | Row scoping is global-off, trusting the connection alone |
| 9 | **Low** | Final-settlement figures are not downline-scoped | Policy checks the permission, not the hierarchy |
| 10 | **Low** | HR document files are company-wide, not owner-scoped | No `TenantFileAccess` resolver for `nic/`, `leave-requests/`, etc. |
| 11 | **Low** | Authenticated API writes are unthrottled | Only `/api/login` has a rate limiter |
| 12 | **Low** | A suspended company's users can still sign in | `companies.status` is stored but never read |
| 13 | **Low** | `is_super_admin` is mass-assignable | In `User::$fillable`; gated only at the form layer |
| 14 | **Info** | Hardening gaps | plaintext env passwords (documented), `.env.example` ships `APP_DEBUG=true`, a demo seeder with no production guard |

---

## 1. Critical — cross-tenant account takeover via invite-then-impersonate

**The chain, each link verified:**

1. `app/Modules/Core/Services/CompanyInvitations.php` — `invite()` looks a user up by
   email and, if the account already exists, attaches it to the inviter's company with
   `$user->companies()->syncWithoutDetaching([$company->getKey()])`. There is no check
   that the account already belongs to another company, and no consent step for an
   existing account (only a brand-new account gets a password-set token; an existing one
   is simply granted access and emailed a notice).
2. `app/Modules/Core/Filament/Resources/Users/Actions/InviteUserAction.php` — the only
   gate is `can('create', User::class)`, i.e. the `UserCreate` permission, which every
   company's own Administrator holds.
3. `app/Support/Impersonation.php` — `canReach()` asks only whether the target is a
   member of the current company and whether the actor is too. Step 1 just made both
   true. `allows()` then needs `UserImpersonate`, which Administrator also holds, and
   refuses only impersonating a super admin — not an ordinary user of another tenant.
4. `app/Modules/Core/Models/User.php` — while impersonating, `getTenants()` returns the
   victim's companies and `canAccessTenant()` passes for the victim's other company, so
   Filament admits the attacker to that company's panel.

**Who can do it, and to whom.** Any Administrator of any company on the installation,
against any account whose email they know. Email addresses are not secret.

**Impact.** The Administrator of tenant A invites `someone@tenant-b` into A, impersonates
them, and switches into tenant B — reading and writing B's payroll, payslips, journals,
bank accounts and employee NIC scans as that user. This is the exact move the
impersonation class advertises that it blocks ("not another company's user"); it is
defeated by first making the victim a member of the attacker's own company. The victim
receives a notification email, which is detection, not prevention.

**Fix (one line, in `invite()`):** refuse to attach an account that already belongs
somewhere unless the inviter is a super admin, so an existing cross-company grant goes
through the platform panel or an accept-token the invitee clicks.

```php
abort_if(! $inviter->isSuperAdmin() && $user && $user->companies()->exists(), 403,
    'That account already belongs to a company; a platform administrator must grant cross-company access.');
```

---

## 2. High — remote code execution through the programme importer

`app/Modules/ConstructionField/Filament/Pages/ProgrammeImportPage.php` builds a
notification body with `Blade::render('<div>'.e(implode(' ', $warnings)).'</div>')`.
The warnings come from `app/Modules/ConstructionField/Services/ProgrammeImport.php`,
which interpolates ids straight out of the uploaded programme file:

> `'A relationship naming an activity outside this file was skipped ('.$link['predecessor_external_id'].' → '.$link['activity_external_id'].').'`

`e()` is `htmlspecialchars` — it escapes `< > & " '` but **not** `{`, `}` or `@`. So an
uploaded file whose activity id is a Blade expression such as `{{ ... }}` is **compiled
and executed** by `Blade::render()` when the warning renders. The warning is produced by
a link that references an activity outside the file, which is trivial to craft.

**Who.** Anyone holding `ConstructionProgrammeUpdate` (a site engineer or PM) in a company
that licenses Construction. Authenticated remote code execution on the server.

**Fix:** never compile user text. Pass an `HtmlString` instead of calling `Blade::render`:

```php
->body(new \Illuminate\Support\HtmlString('<div>'.e(implode(' ', array_slice($summary->warnings, 0, 5))).'</div>'))
```

---

## 3. High — the API login issues a full token without the second factor

`app/Http/Controllers/Api/AuthController.php` validates email, password and account
status and issues a Sanctum token. It never consults
`User::mustUseMultiFactorAuthentication()`. Both Filament panels enforce MFA for
administrators; the API does not. A phished administrator password that cannot open
`/admin` or `/platform` still returns a bearer token from `POST /api/login`, and that
token can name any company through the `X-Company` header (a super admin's
`canAccessTenant()` short-circuits to true). One password becomes every tenant's data
over the API.

This is a gap in the API auth added earlier today, not pre-existing code.

**Fix:** when `mustUseMultiFactorAuthentication()` is true and a factor is enrolled,
refuse the token unless the request also carries a valid one-time code, or issue a
short-lived token scoped to the enrol/verify endpoints only.

---

## 4. Medium — SSRF and internal port-scan via a project environment URL

`app/Modules/Projects/Models/ProjectEnvironment.php` stores a user-set `url`
(`ProjectForm`, `TextInput::make('url')->url()` — a format check, not a host check).
Two services then fetch it server-side with no allowlist:

- `EnvironmentHealthChecker::probe()` does `Http::get($url)` / `head($url)` and returns
  the status, latency and error to the clicker. With `expected_content` set it reads up
  to 256 KB of the body and does a substring match, which is a content oracle. Redirects
  are correctly disabled; TLS verification is off.
- `EnvironmentCertificateChecker` opens `stream_socket_client("ssl://{$host}:{$port}")`
  to an arbitrary host and port parsed from the same URL, and persists connect-success
  and the peer certificate's issuer — a port scanner and a service-identity leak.

Both run on a button press (`ProjectUpdate` to set the URL, `ProjectView` to run it) and
again automatically on the `projects:check-health` schedule, so a malicious URL saved
once is re-fetched by the server on a timer. Reachable targets include loopback services
(Redis, the Reverb and Horizon ports), link-local metadata, and private ranges.

**Fix:** before either call, resolve the host and reject non-`http(s)` schemes and any
address in loopback, private, link-local or CGNAT ranges. One guard, shared by both
checkers.

---

## 5. Medium — stored XSS via SVG upload

`->image()` on a Filament `FileUpload` expands to `acceptedFileTypes(['image/*'])`, and
Laravel's `mimetypes:image/*` rule matches `image/svg+xml` by prefix. The NIC-scan fields
(`EmployeeForm`) and site-photo uploads use `->image()`. `TenantFileController` serves the
stored file inline with the content type taken from the file, and the application sets no
Content-Security-Policy. A member uploads a scripted SVG as a NIC scan; any colleague who
opens `/files/{company}/nic/…svg` runs the attacker's JavaScript on the app's own origin
against their session.

**Fix:** replace every `->image()` with an explicit allowlist —
`->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])` — and add a
Content-Security-Policy header (at least `default-src 'self'`) for the file route.

---

## 6. Medium — the tenant file route accepts a token without the `api` ability

`routes/web.php` guards `/files/{company}/{path}` with `auth:web,sanctum`, but the
`RequireTokenAbility` middleware added today is appended to the **api** group only. A
Sanctum token scoped without the `api` ability — the mechanism the ability check exists
to support — still streams payslip PDFs and NIC scans. No such narrow token is issued
today, so this is a latent gap rather than a live hole.

**Fix:** add `RequireTokenAbility::class` to the file route's middleware array.

---

## 7. Medium — MPR performance-report PDF has a guessable filename

`app/Modules/Mpr/Http/Controllers/MprController.php` names a stored report
`Mpr/{DisplayName}_{unixTimestamp}.pdf` and persists that path. The file is served by
`TenantFileController`, which checks company membership only and then defers to
`TenantFileAccess`, where no resolver claims MPR paths, so access falls through to
"allowed". A colleague who knows a display name (shown throughout the panel) can guess
the filename across the generation window and read another employee's performance report.
The API's own `show()` endpoint is correctly scoped to the caller; the file URL it mints
is not.

**Fix:** name the file with `Str::random(40)` and register an MPR `TenantFileAccess`
resolver that defers to `$user->can('view', $mpr)`.

---

## 8. Medium — isolation collapses silently if the tenant connection is unset

`AppServiceProvider` turns Filament row scoping off globally
(`Resource::scopeToTenant(false)`), relying entirely on the per-company database
connection. `Company::activate()` degrades to setting only the permission team id when
`multitenancy.tenant_database_connection_name` is falsy, and that config resolves from
`env('TENANT_DATABASE_CONNECTION', 'tenant') ?: null`. An **empty**
`TENANT_DATABASE_CONNECTION=` line in `.env` — which reads as `""`, not absent — puts
every company on the default database with no row scoping anywhere and no error. This is
the same empty-env-defeats-fallback trap this project has hit before.

**Fix:** fail fast at boot outside the test environment —
`throw_if(! config('multitenancy.tenant_database_connection_name'))`.

---

## 9. Low — final-settlement figures are not downline-scoped

`FinalSettlementResource` has no `getEloquentQuery()` override and
`FinalSettlementPolicy::view()` is a bare `hasPermissionTo('SettlementView')`, unlike
`PayslipPolicy::view()`, which narrows to the viewer's own record or their reporting
downline via `EmployeeAccess`. Anyone granted `SettlementView` sees every leaver's final
figures regardless of hierarchy.

**Fix:** mirror `PayslipPolicy` —
`isPrivileged($user) || accessibleEmployeeIds($user)->contains($settlement->employee_id)`.

---

## 10. Low — HR document files are company-wide, not owner-scoped

`TenantFileAccess` has exactly one resolver (Construction documents, added today); an
unclaimed path streams to any member of the company. NIC scans, leave medical
certificates and expense receipts therefore reach any colleague holding the URL, with no
`EmployeeAccess` or policy check. Filament's random filenames make this unguessable today,
so it is a single leaked, logged or shared URL away from exposure rather than directly
exploitable — but it is exactly the gap the `TenantFileAccess` hook was built to close.

**Fix:** register resolvers for the `nic/`, `leave-requests/` and `employee-documents/`
prefixes that defer to the owning record's policy.

---

## 11. Low — authenticated API writes are unthrottled

`/api/login` is the only route with a rate limiter. The authenticated API groups are
unthrottled, including `POST /api/refresh` (mints tokens), `POST /api/my-leave-requests`
(an 8 MB upload plus a notification fan-out), `POST /api/payslips/{id}/comments`, and
`GET /api/my-mprs/comparison`, which renders a PDF and writes a file per call. The
comparison files are swept hourly by `mpr:sweep-comparison-exports` (which now runs since
the scheduler fix landed on 2026-10-23), so disk growth is bounded; the burst and the CPU
cost between sweeps are not. Panel login and password reset are throttled by Filament
itself, so those are not findings.

**Fix:** `->middleware('throttle:60,1')` on the `auth:sanctum` API groups, with a tighter
limit on the comparison endpoint.

---

## 12. Low — a suspended company's users can still sign in

`companies.status` is cast and fillable and settable from the platform panel, but no code
path reads it. Suspending a company does not stop its members signing in.

**Fix:** add `(int) $tenant->status === 1` to `User::canAccessTenant()`.

---

## 13. Low — `is_super_admin` is mass-assignable

`User::$fillable` lists `is_super_admin`, `status` and `password`. The only writer today
is the Filament user form, where the super-admin toggle is `->visible(isSuperAdmin())` and
a hidden Filament component is not dehydrated, so a crafted form submission cannot set it.
The risk is latent: any future `User::create($data)` or `$user->update($data)` fed
attacker-influenced data would grant super-admin. Defense in depth.

**Fix:** drop `is_super_admin` from `$fillable` and set it explicitly where legitimately
needed, or add a `saving` guard that refuses to change it unless the actor is a super
admin.

---

## 14. Info — hardening gaps

- **Plaintext environment passwords** (`ProjectEnvironment.password`) are stored and shown
  in clear, documented in `SECURITY.md` as an accepted decision. Any `ProjectView` holder,
  and every database dump, reads production credentials. The one-line upgrade is
  `'password' => 'encrypted'` in `$casts`; it matters most before onboarding contractors,
  which the projects plan already flagged. No model uses an `encrypted` cast today.
- **`.env.example` ships `APP_DEBUG=true`.** Production is correctly `false` (verified on
  the server), but an operator who copies the example without changing it exposes stack
  traces. Ship it `false`.
- **`database/seeders/EmployeeSeeder.php`** hashes a known `password123` and has no
  production guard, where `DatabaseSeeder` and `JournalEntrySeeder` both refuse to run in
  production. It is commented out of the default seed list, so it runs only if invoked
  directly or added back; add the same guard for safety.
- **`config/filesystems.php`** sets `'serve' => true` on the `local` disk, registering
  signed serve routes nothing in the app uses. Harmless (private visibility requires a
  valid signature) but unnecessary attack surface; drop it.

---

## Verified clean — not findings

- **SQL injection.** Every `whereRaw` / `orderByRaw` / `havingRaw` / `DB::raw` /
  `DB::statement` site was read. The report builder resolves columns through the dataset's
  declared allowlist and wraps identifiers with the connection grammar; the aggregate word
  is allowlisted; aliases are internal strings. The forecast and cost-ledger raw fragments
  interpolate only literal column names passed by their callers. The chart bucket
  expressions are driver-switched constants. No request value reaches a raw fragment.
- **Path traversal.** `TenantStorage::isSafePath` rejects empty paths, null bytes and every
  `..` segment after separator folding; the per-company disk is rooted, so absolute paths
  are prefixed, not substituted; Flysystem's normalizer is a second layer. Sound.
- **IDOR on the API.** The payslip, MPR, employee-profile and leave endpoints all derive
  the employee from the authenticated user and never trust an id in the payload; the
  payslip PDF and comment endpoints add `can('view', …)`. The accounts API authorizes on
  every verb through form-request `authorize()`.
- **Policies.** Every model backing a Filament resource has a registered policy; the
  resource `$model` set was diffed against the `Gate::policy` maps in all 27 module
  providers with an empty difference.
- **Cross-tenant request routing.** `ResolveCompanyFromRoute` and `ResolveCompanyFromUser`
  both `abort_unless(canAccessTenant)` before activating and forget the current company in
  a `finally`. The `{company}` path segment is not forgeable and each PDF route adds a
  `can('view')` check.
- **XSS in views.** The unescaped Blade sites render repo-controlled markdown or
  already-escaped content; the one user-data `HtmlString` (the payslip objection) is passed
  through an escaping sanitizer. (The SVG upload path, finding 5, is a separate sink.)
- **CSRF and open redirect.** No CSRF exemptions; the stateless API uses tokens; no
  user-controlled redirect targets.
- **Cryptography.** Signed media links use HMAC-SHA256 over every path component with
  `hash_equals` and an expiry checked first. Tokens use CSPRNG sources. `md5` appears only
  as cache-key digests. Custom-field encryption uses `Crypt::encryptString` (authenticated).
- **Command execution and deserialization.** Every `Process` call uses array-form arguments
  with literals; no `unserialize` of untrusted data anywhere.
- **Secrets.** `.env` is gitignored and untracked; `.env.example` holds only empty keys and
  env references; no private keys committed; the only key-shaped literals are test
  placeholders.
- **Impersonation (the mechanism itself).** Refuses nesting, self-impersonation and
  impersonating a super admin, and keeps the original actor id server-side. The weakness is
  upstream, in how a user becomes a company member (finding 1), not in the impersonation
  checks.

---

## Suggested order of remediation

1. Finding 1 (critical) — one line in `invite()`.
2. Finding 2 (RCE) — one line, remove `Blade::render`.
3. Finding 3 (API MFA) — gate token issue on the second factor.
4. Findings 4 and 5 (SSRF, SVG) — a URL guard and an upload allowlist.
5. The medium file-access and isolation items (6, 7, 8).
6. The low and info items as hardening.

Findings 1, 2 and 3 are each a small, self-contained change and together close the two
critical/high cross-tenant paths and the one RCE.
