## What this is

Leave: asking to be away, and what was decided. You see your own requests and, if
people report to you, theirs — the same rule payslips and expense claims follow,
because a sick-leave record says something about somebody's health.

## Asking for leave <!-- requires: LeaveRequestCreate -->

Click **New**, pick the **type**, and give the dates. A single day is the usual
case: set the first date and the second fills itself in.

The panel above the dates shows **what you have left** of that type this leave
year, and how many days are already requested and awaiting a decision. Those
pending days are shown separately rather than taken off the balance — asking for
leave has not spent it yet, and a balance that dropped the moment somebody asked
would show the same last day as gone to two different people.

**Half days** are offered where the type allows them. Hours are not, deliberately:
nothing else here measures pay in hours, so a two-hour absence would be a note
rather than a leave record.

Some types **ask for a document** — a medical certificate, typically. It is
visible to whoever approves your request and to HR, and to nobody else.

## How many days a request costs <!-- requires: LeaveRequestView -->

**Not the length of the range.** Weekends and public holidays inside the range are
skipped, so Friday to Tuesday over a bank holiday costs two days, not five. Each
day that *was* consumed is recorded separately, which is what lets leave running
from one month into the next be counted correctly in both.

Two things change this:

- If your company has **weekends inside a leave counted** switched on, days
  *between* two leave days are consumed as well — Friday plus Monday then costs
  four days. Only enclosed days ever count; a single Friday always costs one day.
- A **holiday added later** does not go back and change it. The days are worked out
  when the request is approved and never again, because a settled figure that
  quietly moves is worse than one that is slightly generous.

Where the count is not what the dates suggest, the list says so under the number.

## Approving and refusing <!-- requires: LeaveRequestApprove -->

**Approve** shows what the request leaves the employee with before you commit to
it. Going over the entitlement is allowed and is not blocked: somebody over their
balance has usually taken leave the company agreed to, and refusing to record it
makes the register wrong rather than the leave un-taken. The overrun shows on the
balance in red.

**Refuse** requires a reason, which is sent to the person who asked. Being told no
without being told why is the complaint this step exists to answer.

**You cannot normally decide your own leave** — a manager's own request goes to
their manager. A company where there is nobody above to approve can turn that off
in Settings, and each self-approval is then recorded as one in the audit trail. A
waived control that leaves no trace is worse than no control, because the record
then looks as though two people checked it.

### More than one approval <!-- requires: LeaveRequestApprove -->

A company can ask for a chain: Settings → Leave → **Roles that approve after the
first approval**. Name HR, then CEO, say, and a request approved by the manager
waits for somebody holding HR, then somebody holding CEO; the list says which
role it is waiting for and how many approvals it has. Only the last approval
records the days and tells the employee. A refusal at any step refuses the
request. Leave the setting empty and one approval decides, as before.

## Withdrawing <!-- requires: LeaveRequestView -->

**Withdraw** gives the days back. It works after approval as well as before,
because plans change and leave nobody took should not go on costing somebody their
balance. The request stays on the record as withdrawn rather than disappearing.

An **approved request cannot be edited**. Correct it by withdrawing it and filing
again, which leaves both facts visible.

## Two requests cannot claim the same day

Overlapping leave is refused outright. Unlike going over a balance — which is a
judgement somebody can make — being charged twice for one day off is arithmetic
nobody agreed to.

## What leave does not yet do

**It does not change pay.** Unpaid leave is recorded as unpaid, and the days are
counted, but no payslip is reduced by it today. That is a separate switch nobody
has turned on, and when somebody does it will apply from that month forward rather
than to months already settled.

## Roles and permissions

**View**: `LeaveRequestView` — your own and your reports'. **Create**:
`LeaveRequestCreate`. **Update**: `LeaveRequestUpdate`, and only while a request is
still pending. **Delete**: `LeaveRequestDelete`. **Approve or refuse**:
`LeaveRequestApprove` — deliberately separate, because filing leave is not
deciding it, and the Employee role does not hold it.
