# Sole Proprietor and AOP Return Pack

The slab-business return: a business keeping full books whose profit is taxed on the individual and AOP
slab schedule rather than at a company rate. It serves a sole proprietor and an AOP or partnership alike,
since the arithmetic is identical; only the title and one filing footnote differ.

## What it reads

The business profit and loss for the chosen tax year, from the same statements the Corporate pack uses.
A tax year runs July to June and FBR names it for the year it ends in.

## The worksheet <!-- requires: ReportView -->

Structurally the Corporate pack with the tax basis changed:

- **Tax adjustments to accounting profit** — signed rows: a positive amount adds back, a negative one
  deducts. What the ledger cannot know: tax against accounting depreciation, inadmissible expenses.
- **Minimum tax rate (s.113, on turnover)** and **minimum tax turnover threshold** — s.113 binds an
  individual or AOP only at or above the threshold, where a company has none. The threshold moves between
  Finance Acts, which is why it is a field and not a constant.

The statement below the form always computes from what the form shows, saved or not. **Save worksheet**
keeps it, per tax year.

## From accounting profit to tax due

Accounting profit, then the adjustments, then taxable income; then **slab tax on the individual business
schedule** rather than a flat rate, and the greater of that and s.113 minimum tax on turnover once the
threshold is reached.

## The PDF

**Download PDF** prints the pack for the person who files. FBR has no filing API, so nothing here
transmits. A company proper opens the Corporate pack instead. The assessment of a sole proprietor's
business income on the individual schedule is the standard treatment; confirm it with your advisor before
relying on it.
