# EOBI Return

The monthly Employees' Old-Age Benefits Institution return, as a file to upload to the EOBI portal.

## What it reads

One row per employee whose payslip for the chosen month carries an EOBI contribution — the employee's
share and the employer's, read off the payslip's own component rows. Nothing is recomputed: the return
agrees with what was paid, so a rate change later cannot restate a month that has gone.

Contributions are assessed on the minimum wage rather than on actual pay, which is why the employee
figure is the same for everybody. A package below the provincial minimum wage is listed under the table
as a warning, not adjusted.

## When there is nothing to send

The page says why rather than showing an empty file:

- **The EOBI pay component does not exist** — run the statutory component seeder, or create it by hand
  pointing at the EOBI Payable account.
- **No payslip in the month carries an EOBI amount** — the component exists but is on nobody's package.
  Add it to the employees it applies to.

## The file

A plain CSV: employee code, name, CNIC, employee contribution, employer contribution. Every submission
portal accepts it; the scheme's own formats vary by province and year and are not produced here.

Downloading records nothing and submits nothing. Payment of the total is a separate bank payment to the
EOBI Payable account.
