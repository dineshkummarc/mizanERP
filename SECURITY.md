# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| Latest `0.x` release | Yes |
| Anything older | No |

The schema is not yet stable; only the most recent release receives fixes.

## Reporting a vulnerability

**Do not open a public issue.** Email **mali@erbium.ch** with a description,
reproduction steps, and the impact you see.

This is a maintainer-run project with no SLA: expect an acknowledgement within
a week, and a fix on a best-effort basis. You will be credited in the changelog
unless you ask not to be.

## In scope

- Authentication, authorization and policy bypasses (segregation of duties,
  tenant isolation, self-service approval queue)
- Data leaking across tenants
- Injection of any kind, including in the bank-file exporters and PDF rendering

## Known, deliberate decisions — not novel findings

- **Project environment passwords are encrypted at rest** (since 2026-10-26); they remain visible in plain text in the panel to anyone with ProjectView, by
  deliberate decision.** They are shared team credentials for dev/qual/prod
  URLs, and quick copy-paste is the point of the feature. Every user holding
  `ProjectView` can read them, and a database dump exposes them. This is
  documented in the README's Security model and in
  `docs/projects-listing-plan.md` §4, along with the upgrade path (an
  `encrypted` cast plus a reveal permission). Please do not report it.
- **The health checker fetches operator-supplied URLs**, often internal ones.
  It is bounded (HEAD/GET, no redirects, no stored bodies), but it can probe
  whether a host answers. Reports about that bounded behaviour itself are out
  of scope; a way to *escape* those bounds is very much in scope.
