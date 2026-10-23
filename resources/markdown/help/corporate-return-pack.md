# Corporate Return Pack

The company's income tax return, assembled from the ledger, with the worksheet that turns accounting
profit into taxable income kept beside the figures it changes.

## What it reads

The profit and loss for the chosen tax year, from the same statements the Reports hub prints. A tax year
runs July to June and FBR names it for the year it ends in, so the books' 2025-2026 is Tax Year 2026.

## The worksheet <!-- requires: ReportView -->

Accounting profit is not taxable income. Tax depreciation, inadmissible expenses and their kin live in the
Ordinance rather than in the books, so between the profit and loss and the tax sits a small hand-kept
worksheet:

- **Tax adjustments to accounting profit** — signed rows: a positive amount adds back, a negative one
  deducts. Name each one the way your practitioner would.
- **Brought-forward loss** — a prior year's business loss set against this year's taxable income, not
  below zero.
- **Corporate rate** and **minimum tax rate (s.113, on turnover)** — the two rates a Finance Act moves.
  A small company's rate differs.
- **Super tax (s.4C)** — an amount, not a rate, because its slabs move yearly. Your practitioner computes
  it and it adds on top of the tax due.

The statement below the form always computes from what the form shows, saved or not. **Save worksheet**
is what makes it survive the session; it is stored per tax year.

## From accounting profit to tax due

Two computations IRIS runs, shown side by side: normal tax on taxable income, and section 113 minimum
tax on turnover. The greater applies.

## The PDF

**Download PDF** prints the pack for the person who files. FBR has no filing API, so nothing here
transmits: the last step is somebody on iris.fbr.gov.pk with this pack beside them, or a practitioner
handed the PDF. Business companies only — a personal account has its own return pack, and a sole
proprietor or AOP uses the slab-business pack.
