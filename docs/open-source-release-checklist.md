# Open-source release checklist

**Status:** Section 1 is **closed as far as the working tree goes (2026-09-21)** — and *not* as far as git
history goes, which is what still blocks publishing. See §1.6.
**Created:** 2026-07-28

Work top to bottom. Section 1 must be finished *before* the repository is ever made
public or pushed to a public remote, because a public commit cannot be recalled: it is
mirrored, cached and scraped within minutes.

---

## 1. Blocking — data that must not be published

These are things I found actually committed in this repository today.

### 1.1 Real employees' names and personal email addresses

`database/seeders/EmployeeSeeder.php` contains **16 real people**, including personal
Gmail addresses, and their reporting lines. That is other people's personal data, and
publishing it is not the maintainer's call to make on their behalf.

Replace with obviously fictional data — `faker` or a fixed list of invented names on an
`@example.test` domain. The seeder's *purpose* (a realistic org tree with managers) is
worth keeping; the identities are not.

### 1.2 Company compensation figures

`database/seeders/EmployeeSettingSeeder.php` carries real allowance and deduction
amounts (petrol, device, meal deduction). Round numbers that reveal a pay structure.
Substitute arbitrary demo values.

### 1.3 Named company and default super admin

- `database/seeders/CompanySeeder.php` — the real registered company name and slug
  (`ERBIUMTECH (SMC-PRIVATE) LIMITED`). No NTN, so nothing regulated here.
- `database/seeders/DatabaseSeeder.php` — `admin@erbium.tech` with password
  `password`.

Neither is dangerous on its own, but a known default credential shipped in a public
repo *will* be tried against your production host. Use `admin@example.test`, and
generate a random password printed once at seed time.

### 1.4 Bank reference files of uncertain licence

- `docs/IMD CODES IBFT.xlsx`
- `docs/ipayments_csv (002).csv`

These came from SBP/1LINK/Standard Chartered material. Confirm you have the right to
redistribute them; if not, remove them and document the format instead. The bank
directory itself can be seeded from a list you compile.

### 1.5 Files that should never have been committed

- `public/.idea/*` — 6 PhpStorm project files, committed inside the **web root**.
  Remove from the index and add to `.gitignore`.
- `public/link.php` — gone from the working tree, but it **is in history** (commit
  `c326856 "update link"`). A stray PHP file in the web root is the shape of a
  webshell and reviewers will read it that way, so make sure the history rewrite in
  1.6 removes it rather than publishing it. Worth understanding why it existed.
- `.gitignore` additions: `/public/.idea`. Already covered and verified:
  `database/tenants/*.sqlite` (via `database/tenants/.gitignore`), `.env`, `/storage/*`.
- `.gitattributes` — add `/tests export-ignore` and `/docs export-ignore` **only if**
  you publish to Packagist as a dependency; for an application repo, leave them in.

### 1.6 Git history retains all of the above

Deleting these files in a new commit is **not enough** — every earlier commit still
contains them, and GitHub will happily serve the old blobs.

Two options:

| Option | When to choose it |
|---|---|
| **Fresh repository, squashed initial commit** (recommended) | Almost always. Scrub the working tree, `git init` a new repo, one "Initial public release" commit, push that. Simple, no chance of a missed blob. You keep the private repo with its full history. |
| `git filter-repo` to rewrite history | Only if the commit history itself has value worth publishing. Slower, easy to get wrong, and every collaborator must re-clone. |

Whichever you pick, run a secret scan on the result before pushing:

```bash
# any credentials left in the tree or history
gitleaks detect --source . --verbose
trufflehog filesystem . --results=verified
```

### 1.7 Rotate anything that was ever in a private repo

Treat every credential that has appeared in git — API keys, `APP_KEY`, DB passwords,
the Slack token in `config/services.php`'s env keys — as compromised on the day you
publish, and rotate it. `APP_KEY` matters especially here: it decrypts sessions and any
`encrypted` cast.

---

### 1.6 What was done, 2026-09-21

Every item above is out of the working tree. One is not out of the repository, and the difference is the
whole of what is left.

- **1.1 / 1.2 / 1.3 — the real roster, the real pay figures, the real company.** Already moved once, into
  `database/seeders/Production/`, which took them out of `db:seed` but left them committed. They are now
  **untracked and gitignored**, with `database/seeders/Production/README.md` explaining the rule and the
  shape for anyone writing a replacement. The files stay on the machines that have them — including the app
  server, where a deploy's `git reset --hard` leaves untracked files alone — so nothing broke.
- **1.3, the credential.** The seeded address was already `admin@example.test`. The weak password stays for
  demos and developer machines, where randomising it would trade real convenience for no security, and
  `DatabaseSeeder::superAdminPassword()` now **refuses it outright on a production host** unless
  `SEED_ADMIN_PASSWORD` is set. A known default credential that cannot be seeded in production is not a
  known default credential.
- **1.4 — the bank reference files.** Untracked rather than relicensed: the licence question is unanswered,
  and the formats they document are written up in `docs/accounting-implementation-plan.md`, which is what
  `BankSeeder` and `SalaryBankExportService` actually cite. Nothing reads the files themselves.
- **1.5 — `public/.idea/*`.** Six PhpStorm files removed from the index and ignored, along with a top-level
  `/.idea`.

**One hazard the untracking creates, named here because it has already happened once:** a gitignored file
is a file `git clean -fdx` removes without asking, and the five real seeders went missing from a working
tree and had to be recovered with `git show`. That recovery works only while the history still holds them —
which is exactly what the rewrite below destroys. Copy them somewhere access-controlled *before* rewriting
anything; `database/seeders/Production/README.md` says the same where somebody will actually read it.

**Rewritten 2026-09-26 with `git filter-repo`, all fourteen branches and three tags force-pushed.**
The method, so it can be trusted and repeated: markers (every personal email and name field) were
extracted from the historical blobs of the sensitive files; any marker still in HEAD was classified as
deliberately kept (owner, company, banks), leaving 44 history-only markers. Ten paths were removed from
every commit (the five `Production/Real*` seeders, the two bank docs, `public/.idea/`, `public/link.php`,
a committed `storage/debugbar/` dump) and the 44 markers redacted to `[scrubbed]` in old blobs of the five
files that still exist. Verified before pushing: **zero marker hits across every blob of every ref**, the
sensitive paths gone from all history, HEAD's tree byte-identical. Backups (real seeders, pre-rewrite full
GitHub mirror) live outside the repository in `~/Work/learning/mizan-prerewrite-backup-2026-09-26/`.

**What the rewrite cannot reach, still open:** GitHub retains the old commits through merged-PR refs
(`refs/pull/*`, e.g. #17) and SHA-addressed caches — only a GitHub Support request ("remove sensitive
data" / run gc) clears those; any pre-rewrite fork or clone keeps everything; and **1.7 credential
rotation is unchanged and still required**. Local machines with old clones must
`git fetch && git reset --hard origin/master`.

## 2. Files GitHub and contributors expect

**All present, checked 2026-10-26.** What still stands before publishing is §1.7 (rotate every credential
that was ever in the repository — nothing here can verify that) and, from §1.6, the GitHub Support
request that clears the pre-rewrite commits GitHub still serves; then the `v0.1.0` tag in §3.

| File | Notes |
|---|---|
| `LICENSE` | **A repo with no licence is not open source** — default copyright means nobody may use it. MIT is the Laravel-ecosystem norm and the README already claims it; add the file or change the README. |
| `README.md` | Done. Add screenshots or a short GIF — for an admin panel this is the single highest-value addition. |
| `CONTRIBUTING.md` | How to set up, run tests, code style (Pint), what a good PR looks like, and that domain changes need tests. |
| `CODE_OF_CONDUCT.md` | Contributor Covenant 2.1, verbatim, with a real contact address. |
| `SECURITY.md` | Private reporting route, what's in scope, response expectations. Must state the plain-text environment-password decision explicitly so it isn't reported as a novel finding. |
| `CHANGELOG.md` | Keep a Changelog format. Start at `0.1.0` — the schema is not stable. |
| `.github/ISSUE_TEMPLATE/bug_report.yml` | Ask for PHP/Laravel version, tenant vs landlord, and whether the queue/scheduler are running — those three explain most reports. |
| `.github/ISSUE_TEMPLATE/feature_request.yml` | Ask which country's rules it applies to. |
| `.github/PULL_REQUEST_TEMPLATE.md` | Checklist: tests pass, Pint clean, migration is tenant-path aware. |
| `.github/dependabot.yml` | Composer + npm + github-actions, weekly. |
| `.github/workflows/tests.yml` | Matrix on PHP 8.3/8.4: `composer install`, `php artisan test`, `pint --test`. |
| `.editorconfig`, `.gitattributes` | Both already present and sensible. `.gitattributes` already `export-ignore`s `/.github` and `CHANGELOG.md`. |

## 3. Repository settings

**Applied 2026-09-26 via the API**, except two: description, topics, wiki/projects off,
issues + discussions on, private vulnerability reporting, Dependabot alerts, secret scanning
with push protection, and branch protection on `master` (the actual default branch, not
`main`): `laravel-tests (8.4)` must pass, one approving review, no force-push, no deletion —
`enforce_admins` off so the owner's direct pushes keep working until external PRs begin.
Not done, deliberately: `FUNDING.yml` (only if wanted) and the `v0.1.0` tag — the pending
§1.6 history rewrite replaces every commit, which would orphan a tag placed now; tag after
`git filter-repo`.

- **Description** — one line, plus the URL. Do not leave it empty; it's what search shows.
- **Topics** — `laravel`, `filament`, `php`, `accounting`, `payroll`, `double-entry`,
  `hrms`, `multi-tenant`, `pakistan`, `erp`.
- **Branch protection** on `main`: require the test workflow to pass, require one
  approving review, no force-push, no deletion. Set it before the first external PR.
- **Disable wiki and projects** unless you intend to use them — empty tabs read as
  abandonment.
- **Enable** issues, discussions (better than issues for "how do I…"), and **private
  vulnerability reporting** (Settings → Security).
- **Enable Dependabot alerts + secret scanning + push protection**. Push protection is
  the one that stops the next accidental key commit.
- **Add `FUNDING.yml`** only if you actually want it.
- **Tag a release** (`v0.1.0`) so people can pin something. An unreleased default branch
  discourages adoption.

## 4. Before announcing

- Deploy the public README's quick start on a clean machine and follow it literally.
  It is the only way to find the step you know by heart and forgot to write down.
- Decide and document what you will support. "Issues answered, no SLA, PRs reviewed
  when I can" is a fine and honest answer.
- Decide the tax/bank-file maintenance story: those need updating every Finance Act,
  and a stale tax table in a payroll tool is worse than no tool. Say so in the README
  if you cannot promise it.

## 5. Repository name

The README currently uses **Mizan** (میزان — *balance/scales*; a trial balance is
*mīzān-ul-ḥisāb*). Short, pronounceable, and it means the exact accounting concept the
app is built on.

| Name | For | Against |
|---|---|---|
| **`mizan`** ★ | Meaningful, short, memorable, ties to double-entry | Common word — check GitHub/Packagist/npm and trademark availability |
| `khata` | "ledger book"; instantly understood across South Asia | Also a common word; several existing fintech products use it |
| `roznamcha` | Literally the accounting daybook | Hard to spell or say outside Urdu/Hindi speakers |
| `hisaab` | "accounts/reckoning"; widely understood | Vaguer than *mizan* |
| `mizan-erp` / `mizan-hr` | Disambiguates a common word | Clunkier, and "ERP" oversells current scope |
| `laravel-payroll-pk` | Honest, searchable, self-describing | No identity; ages badly if it outgrows Pakistan |

**Recommendation:** `mizan`, with the description doing the explaining — *"Multi-tenant
HR, payroll and double-entry accounting for Pakistani businesses (Laravel + Filament)."*
Fall back to `mizan-hr` if the bare name is taken. Keep `MPR` out of the public name: it
means nothing to outsiders and collides with dozens of acronyms.

### 5.1 Done, code side (2026-09-26)

- GitHub repo and composer package are `erbiumtech/mizan`; README is titled Mizan with a
  `mizan` clone URL; `Envoy.blade.php` deploys to `/var/www/mizan`.
- `.env.example` `APP_NAME=Mizan`, `config/app.php` fallback `'Mizan'` (was the Laravel
  skeleton default in both), `composer.json` description/keywords set to the line above
  (was the skeleton boilerplate).
- **Not renamed, on purpose:** `app/Modules/Mpr` and its `mpr` module key — that is the
  *Monthly Progress Report* domain module, not the app name; the key is stored in module
  toggle/permission data, so renaming it is a data migration for no branding gain.

### 5.2 Left manual, because a live deploy references each

- **Production `APP_NAME`** on the VPS — `cache`/`redis`/`horizon` prefixes, the session
  cookie name and backup names all derive from `Str::slug(env('APP_NAME'))`; changing it
  logs everyone out, orphans queued horizon jobs and breaks backup-monitor continuity.
  Change it during a maintenance window, or accept the old value silently.
- **Database names** (landlord + per-tenant) — referenced by the live `.env`, backup
  jobs and the tenant registry; renaming is a migration with downtime, not a find-replace.
- **The local checkout directory `mpr`** — other sessions and tooling depend on the
  path; a fresh clone of `erbiumtech/mizan` retires it naturally.

---

## Suggested order

1. Section 1, all of it. Then verify with a secret scan.
2. Fresh repo, squashed initial commit, still private.
3. Add section 2 files; get CI green.
4. Apply section 3 settings.
5. Flip to public, tag `v0.1.0`, then announce.
