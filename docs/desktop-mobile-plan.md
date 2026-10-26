# Desktop and mobile clients: the plan

> Written 2026-09-27 from a code inventory, not aspiration. Nothing below is built
> unless marked so. Phase 0's questions gate everything after them.

## 0. What is true today, and the one fact that reorders everything

The codebase is a server-rendered Filament panel with a deliberately small mobile API
bolted to its side. The API is real and consistent where it exists:

- **15 endpoints across five modules** — payslips (`/api/my-payslips`, per-payslip PDF,
  comments), leave (`/api/my-leave-balances`, `/api/my-leave-requests`), MPRs
  (`/api/my-mprs`, comparison, show), profile (`/api/my-profile`), accounting
  (`/api/reports/trial-balance`, `profit-and-loss`, `accounts` resource + tree). Every
  one carries the same stack: `auth:sanctum` + `api.company` (X-Company tenant
  resolution, `ApiCompanyResolutionTest`) + `module:*` licence gating.
- **Files for API clients work**: `/files/{companyId}/{path}` accepts a Sanctum bearer
  (`auth:web,sanctum`, `TenantFileController`) precisely so the `pdf_url` values the API
  returns open on a phone. WhatsApp media uses the hand-rolled path-segment HMAC
  (`PayslipMediaLink`) because Twilio mangles query strings.
- **Realtime exists, push does not**: 17 notification classes broadcast over Reverb on
  one private user channel; there is zero FCM/APNs/device-token code anywhere.
- **A mobile client already ships.** The repo does not contain it, but the code is
  written for it: `routes/api.php` warns "check the shipped builds before revoking a
  licence in production", `MprController` returns absolute URLs "because a mobile client
  resolves nothing relative". Someone is already holding shipped builds this API can
  break.

That last fact reorders the plan. A "future mobile app" plan could defer API hygiene;
an API with shipped consumers cannot:

- `POST /api/login` is **unthrottled** — free credential-stuffing against a public
  codebase whose login route everyone can read.
- Tokens **never expire** (`sanctum.expiration = null`), carry **`["*"]` abilities**,
  are all named `'API Token'`, and there is **no logout/revoke endpoint**. A phone lost
  in a rickshaw is a permanent credential.
- `login` returns the **unfiltered user model**, and the response envelopes disagree
  (`{success, message, ...}` hand-rolled in five controllers, bare `{data}` from the one
  `JsonResource`; `MprController` answers 400 where 404 is meant).
- **No versioning** of any kind, with external builds already in the field.

So Phase A1 is not preparation for a client — it is overdue maintenance of one.

## 1. Decisions (the shape, argued once)

**D1 — The mobile app is a companion, not the ERP.** Employee and manager self-service:
payslips, leave, approvals, notifications, profile. The panel remains the only surface
for accounting, payroll runs, configuration — rewriting a 5,000-test ERP natively is the
project this plan exists to refuse. `docs/construction-management-plan.md` already
refused a native field app on the same grounds.

**D2 — PWA first, native shell only when a named need appears.** The candid comparison:

| Route | For | Against |
|---|---|---|
| **PWA on the existing panel** ★ | Zero new codebase; self-service pages already exist as Filament pages; installable on Android/iOS/Windows/macOS; web push covers Android + desktop natively and iOS 16.4+ when installed | iOS push only after home-screen install; no store presence; no biometric unlock |
| Capacitor wrapping the PWA | Store presence, FCM/APNs push, biometrics — while keeping the one codebase | Store accounts + review cycles; a webview app must still feel like an app |
| Flutter / React Native | Real native feel, offline primitives | A second full codebase in a language the team does not write, against an API of 15 endpoints — the tail wags the dog |
| NativePHP / Electron desktop | — | The app is multi-tenant server-side; a local runtime contradicts the architecture. Refused. |

PWA first because the marginal cost is a manifest, a service worker, and mobile polish
on pages that already render — and because it is the same artifact desktop installs
(Chrome/Edge "install app"). Capacitor is the sanctioned upgrade path and reuses
everything; Flutter is the path only if the companion someday outgrows a webview, which
is a decision for that day.

**D3 — Desktop is the PWA.** The panel already is the desktop app; installability makes
it feel like one (dock icon, own window, notifications). A Tauri wrapper is gated
behind a concrete OS-level need — tray presence, kiosk lockdown for the retail till
(`docs/retail-stores-pos-plan.md` §5 owns that decision), auto-start. None exists today.

**D4 — Version the API before touching its shapes.** `/api/v1/...` aliases added while
every existing unprefixed route keeps answering, deprecated, until the shipped builds
are confirmed migrated (the same courtesy `docs/modules-plan.md` §risks demanded for the
403 change). New endpoints land under `/v1` only.

## 2. Phase 0 — questions that gate the rest

1. **Where is the shipped mobile client?** Which repo, which framework, who maintains
   it, what does it call today? Everything in the M-series either inherits it or
   supersedes it, and nobody should build a companion app twice. *(Gates M1.)*
2. **Scope of the companion**: employee-only (payslips/leave/notifications), or
   manager approvals too? *(Gates A4/M3.)*
3. **Store presence required?** If the answer is yes on iOS, Capacitor + an Apple
   developer account enter at M4; if no, the PWA route runs the whole distance.
   *(Gates M4.)*
4. **Push provider**: Web Push (VAPID, no vendor account, works for PWA) vs FCM
   (needed the moment Capacitor/native enters). Building the device-token table and the
   notification seam identically for both is cheap; choosing the first transport is not
   reversible-free. *(Gates A3.)*
5. **Offline-first workflows** — *answered 2026-09-27: all four* (construction field,
   employee self-service, retail till, MPR field entry), which promotes offline sync
   from a per-plan afterthought to the platform capability designed in §5a. Order there
   is by engineering risk, not preference; the till waits on the retail module existing.

## 3. A-series: API hardening (build first, client-agnostic)

**A1 — Auth lifecycle.** **Built 2026-10-26** — `ApiAuthTest`; tokens issued before carry `*` and keep working until they expire thirty days after issue. Throttle `POST /api/login` (`RateLimiter`, per-email+IP, the
`LeadCaptureController` pattern already in the repo); token expiry via
`sanctum.expiration` plus a refresh flow; `createToken($deviceName, $abilities)` with a
required `device_name` in the login payload; `POST /api/logout` (revoke current),
`GET/DELETE /api/tokens` (list/revoke devices); the login response slimmed to a
deliberate resource, not `$user` raw. One test file pinning each behavior.

**A2 — One envelope, one error grammar, `/v1`.** **Built 2026-10-26** (`ApiVersioningTest`): every route answers under both `/api` and `/api/v1` through `App\Support\ApiRoutes`, the five hand-rolled controllers answer through `App\Http\ApiResponse`, MprController's comparison is 404 on `/v1` and 400 unprefixed, and `docs/api/openapi.yaml` is the 15-endpoint spec with the error grammar. A thin `ApiResponse` support class (or
plain `JsonResource`s — `AccountResource` is the precedent) adopted by the five
hand-rolled controllers; `MprController`'s 400→404 corrected **under `/v1` only** (D4);
module-gate 403 and tenant-resolution 422 documented in one place. Publish a static
OpenAPI file in `docs/` — 15 endpoints is a morning, and the shipped-client author needs
it more than we do.

**A3 — Push.** `device_tokens` table (landlord — devices belong to users), registration
endpoint, and one seam: the existing `Broadcasting::channels()` (built for the Slack
gate) grows push the same way — every one of the 17 `toBroadcast` classes inherits it
for free, payload identical to the database/broadcast shape. Transport per Phase 0 Q4;
the seam does not care.

**A4 — Companion endpoints, scope per Phase 0 Q2.** Likely: notifications
(list/mark-read — the database notifications already exist), leave approvals for
managers (`LeaveRequestService::decide()` is the seam), expense claim approvals.
Only what the companion's screens actually draw.

## 4. M-series: the companion app

**M1 — PWA-ify the panel.** Manifest (name/icons from the per-company branding that
`CompanyLetterhead` now serves — the tenant-aware brand landed 2026-09-27), a service
worker that caches the app shell and answers offline with a "you're offline" page (not
offline data), installability verified on Android/iOS/desktop Chrome. Mobile polish
pass over the self-service pages only: payslip list/view, leave apply/balances,
notifications. Filament is responsive; polish means thumb-sized, not redesigned.

**M2 — Push wired end to end.** A3's transport delivering the 17 notifications to an
installed PWA (and/or the shipped native client through FCM); notification tap opens
the right screen.

**M3 — Manager approvals** on mobile (per Phase 0 Q2): approve/return leave and expense
claims from the notification. This is the feature that makes managers install it.

**M4 — Native shell, gated.** Capacitor around the same PWA if Phase 0 Q3 demands store
presence/biometrics/FCM-only push. Explicitly not scheduled until the PWA has users
asking for what it cannot do.

## 5a. O-series: offline-first sync (decided 2026-09-27 — all four workflows)

One engine, four consumers. The design leans on a single load-bearing choice: **every
offline capture is an append-only document**, so most of "sync" stops being a conflict
problem and becomes an idempotency problem — which this codebase already knows how to
hold (the FBR submission job's fixed idempotency key is the in-repo precedent).

**The engine, in six rules:**

1. **Client outbox.** Mutations made offline are recorded locally (IndexedDB in the
   PWA; SQLite if/when Capacitor enters) as commands: a client-generated ULID, the
   workflow name, the payload, and `captured_at` device time. Replayed strictly in
   order when connectivity returns, with backoff.
2. **Server inbox.** `POST /api/v1/sync/{workflow}` accepts a command batch under the
   same stack as everything else (`auth:sanctum` + `api.company` + `module:*`). A
   tenant `sync_commands` table keys on the client ULID: a replayed command returns its
   original result instead of applying twice. Device clocks are recorded
   (`captured_at`) but never trusted for ordering — `received_at` server time is
   canonical.
3. **Incremental reads.** Per-workflow pull endpoints with an `updated_since` cursor
   over `updated_at`, tombstones via the soft-deletes the models already carry. No
   generic changes feed until a workflow demonstrates the cursor is not enough.
4. **Conflicts, by shape rather than by engine.** Append-only documents (attendance
   punches, daily-log entries, MPR entries, till sales) cannot conflict — the server
   assigns canonical ids and duplicates collapse on the ULID. The few mutable documents
   (a punch-list item's status) carry the `base_version` they were edited from:
   matching version applies; stale version keeps the server's value and files the
   client's as a flagged comment for a human — the activity log records both. No
   CRDTs, no merge UI.
5. **Blobs ride behind their command.** Photos queue after the command that references
   them, upload one at a time with their own idempotency key to the tenant-scoped disk
   (the branding-upload path), capped in size; a document is visibly "syncing, N photos
   pending" until its blobs land.
6. **The ledger never hears from a device.** Offline captures land as staged documents;
   posting happens server-side on sync through the same services online writes use
   (a till sale stages, then posts through `recordPayment()`/`InventoryService`;
   attendance recalculates payroll server-side). Double-entry integrity stays behind
   one door. This rule is not negotiable and is the reason the engine can be simple.

Auth for offline windows: A1's token expiry must leave room for a device that is dark
for days (site rotations) — device tokens in the 30-day range with refresh-on-sync,
`X-Company` pinned per device profile, and the module-gate 403 handled as "stop
syncing, tell the user", per the modules-plan risk note.

**Phases, ordered by engineering risk:**

| Phase | What | Proves / adds | Gate |
|---|---|---|---|
| O1 | Engine core + **attendance punches** | Outbox, inbox idempotency, cursor reads — on the simplest append-only shape there is | A1, A2 |
| O2 | **Construction field**: daily logs, punch lists, inspections, photos | Blob queue, the one mutable-document policy (`base_version`), site-day offline windows | O1 |
| O3 | **MPR field entry**: compose offline, sync up | First write API for MPRs (today read-only), draft states | O1 |
| O4 | **Retail till**: offline sales | Staged-sale posting through the ledger door | O1 **and** the retail plan's own phases 1–2 (stores/tills exist) |

Each phase lands with its own feature tests: replay-idempotency, out-of-order delivery,
the stale-`base_version` conflict trail, and a killed-mid-batch resume.

## 5b. Whole-ERP offline replication: what it would take (asked 2026-09-27, costed here)

The question was asked, so the refusal gets its price tag instead of a shrug. Full
write-capable replication of this ERP means, concretely:

1. **A second domain runtime.** The rules live in PHP services (`JournalEntryService`,
   `TaxCalculatorService`, the withholding guards, leave arithmetic). Offline writes run
   those rules on the device: rewrite them all in a client language and maintain two
   implementations in lockstep forever, or ship the PHP app per device (the NativePHP
   route §6 refuses — the product is server-side multi-tenant).
2. **Bidirectional replication of ~104 tenant tables** — oplog/row versions on every
   table, tombstones, snapshots + incrementals, migration coordination per replica, and
   per-device filtered subsets that re-implement the 359-permission model inside sync.
3. **Distributed accounting semantics** — per-device number blocks for every sequenced
   document (the retail plan's §12 answer, times everything), balance-dependent checks
   that stale local data cannot enforce, and financial conflicts resolved by accounting
   reversal workflows because last-write-wins is wrong by definition for money.
4. **Validation built twice** — device-side enforcement is advisory on hardware the
   user controls, so the server re-validates every replayed mutation against the state
   that held when it was captured.
5. **Whole tenant databases on personal phones** — payroll included: encryption at
   rest, remote wipe, MDM, and a multiplication of exactly the exposure the 2026-09-26
   history rewrite existed to end.
6. **Permanent ops** — convergence tests, divergent-replica debugging, oplog compaction,
   device lifecycle. Off-the-shelf local-first engines (PowerSync/ElectricSQL) cover
   only data movement, for Postgres/SQLite stacks, and none runs PHP domain logic.

That is a multi-year re-architecture — a distributed database plus a portable domain
layer — and it stays refused. What is NOT refused is the part people usually mean:

**O5 — offline read replica (the middle path, adoptable when M1+O1 exist).** The PWA
caches read models on device — dashboards, ledgers, contacts, payslips, reports —
served stale-while-offline and stamped "as of <time>"; every write still goes through
the §5a outbox. Browsing the whole ERP on a plane, writing only what a named capture
workflow allows. Rule 6 survives untouched; new offline *writes* are added one named
workflow at a time, never by replication.

## 5. D-series: desktop

**D1 — ships with M1.** The PWA manifest makes the panel installable on Windows/macOS;
web push (A3) gives desktop notifications. Nothing else to build.

**D2 — Tauri wrapper, gated** on a named OS-level need. The first candidate on record
is the retail till (kiosk mode, cash-drawer/printer peripherals), which belongs to the
retail plan's own phases, not this document.

## 6. Not doing, so nobody re-litigates it

- **Native rewrite of the ERP** — the panel is the product; the companion is a satellite.
- **NativePHP/Electron** — a local Laravel runtime against database-per-tenant hosting
  is an architecture contradiction, not an option.
- **Whole-ERP offline replication** — offline-first sync is now designed and phased in
  §5a, but its scope is the four named capture workflows. Replicating ledgers, payroll,
  or configuration to devices stays refused: the ledger never hears from a device
  (§5a rule 6), and everything outside a named capture workflow requires connectivity.
- **An API for every panel feature** — endpoints follow companion screens, never
  speculation. YAGNI is why the current 15 are all real.

## 7. Order of work

| Step | What | Gate |
|---|---|---|
| 0 | Phase 0 answers (esp. locate the shipped client) | — |
| 1 | A1 auth lifecycle + login throttle | none — overdue regardless |
| 2 | A2 envelope + `/v1` + OpenAPI | A1 |
| 3 | M1 PWA + mobile polish | Phase 0 Q1/Q2 |
| 4 | A3 push seam + M2 delivery | Phase 0 Q4 |
| 5 | A4 + M3 approvals | Phase 0 Q2 |
| 6 | O1 engine + attendance punches | A1, A2 |
| 7 | O2 construction field offline | O1 |
| 8 | O3 MPR offline entry | O1 |
| 9 | M4 Capacitor / D2 Tauri | a named need, in writing |
| 10 | O5 offline read replica (§5b) | M1, O1 |
| 11 | O4 retail till offline | O1 + retail plan phases 1–2 |

A1 is the only step with no gate: an unthrottled login issuing immortal all-ability
tokens for a public codebase does not wait for a mobile strategy.
