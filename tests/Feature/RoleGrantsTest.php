<?php

namespace Tests\Feature;

use App\Support\ModuleManifest;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The five roles, composed from what the modules grant.
 *
 * `RoleSeeder` held ~150 grants as literal per-role lists, which made adding a module an edit to a central
 * file — the last of the six that phase 3 set out to remove, and the one it left behind: `ModuleManifest` has
 * had the `role_grants` merge slot since then with nothing filling it. See docs/module-packaging-plan.md §5.
 *
 * The refactor was proved behaviour-preserving by snapshotting every role's permission set before and after
 * and diffing them. What this file protects is the part a snapshot cannot: that the *composition* still holds
 * once the lists are spread across 15 manifests, where nobody reads them together.
 *
 * The counts are asserted deliberately: a change to any of them means somebody has widened or narrowed a role,
 * which is a decision worth failing on rather than a detail. Update them in the same commit as the grant, and
 * say why — the construction entry on `EXPECTED` is what that looks like, and it is the mechanism working
 * rather than an inconvenience.
 */
class RoleGrantsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /**
     * What each role holds, and every change to these numbers is a decision.
     *
     * The baseline was 27 / 81 / 94 / 106 — what the pre-refactor seeder produced, snapshotted and diffed to
     * prove the move into the manifests changed nothing.
     *
     * **2026-08-17, construction Phase 1** (`docs/construction-management-plan.md`) moved them twice, and both
     * times this test failed until the numbers were changed on purpose, which is what it is for.
     *
     * Phase 1a, the four job permissions: Employee +1 (`ConstructionJobView` — a site engineer reads the job
     * they are on, and row scoping rather than the permission is what narrows it); Accountant +3 (view, create,
     * update — the commercial side maintains jobs); Manager +3, inherited with no addition of its own; CEO +4,
     * the inherited three plus `ConstructionJobDelete`, which the policy further refuses on a closed job.
     *
     * Phase 1c, the four cost-code permissions, on the same shape: Employee +1 for view, because a material
     * issue has to name a code and a picker with nothing in it is a form nobody can complete; Accountant +3,
     * because the library is what the next tender is priced from; CEO +1 for delete, which the policy further
     * refuses on a code that has children.
     *
     * Phase 2, the six cost-ledger permissions: Employee +1 for view — a site engineer sees what the job has
     * cost; Accountant +3 to record it; **Manager +2**, for `ConstructionCostReverse` and
     * `ConstructionPeriodClose`, both approval-shaped and kept away from whoever recorded the cost; CEO +1 for
     * `ConstructionPeriodForceClose`, which is closing over an unexplained difference between the two ledgers
     * and is the one that needs a name on it.
     *
     * Phase 1d, the five document permissions — and this is the run where the ratchet earned itself. Employee
     * +1 for view; Accountant +3; **Manager +1 for `ConstructionDocumentPublish`**, its first addition of its
     * own here, because publishing is what says "build this" and belongs with the approval powers rather than
     * with whoever uploads drawings; CEO +1 for delete. Working out why Accountant moved by 3 rather than 2 is
     * what surfaced a real hole: the roles are separate leaves, not a chain, so Accountant does not inherit
     * Employee's view — and without it a surveyor could upload a drawing and then not be able to open it.
     *
     * Phase 3, the six budget, measurement and forecast permissions: Employee +1 for `ConstructionBudgetView`,
     * because a figure the site team cannot see is a figure they cannot work to; **Accountant +4** — the surveyor
     * builds the budget, measures progress and prepares the forecast, which is four of the six; **Manager +1 of its
     * own** for `ConstructionBudgetApprove`, approval-shaped and kept away from whoever priced it; **CEO +1** for
     * `ConstructionBudgetBaseline`, which decides what every earned-value figure on the job is measured against and
     * restates all of them if it moves, so it sits with whoever answers for the numbers.
     *
     * Phase 4a, the five contract permissions: Employee +1 for view, because people build to the
     * specification, the dates and the damages, and a contract they cannot open is one they cannot work to;
     * Accountant +3 to raise and price one; **Manager +1 of its own** for `ConstructionContractExecute`, which
     * freezes the scheduled values every later certificate is measured against; **CEO +1** for delete, which
     * the policy further refuses on anything but an empty draft with no subcontracts under it.
     *
     * Phase 4b, the five variation permissions: Employee +1 for view, because an instruction nobody on site can
     * see is work that gets built to the superseded drawing; Accountant +4 — the surveyor raises, describes,
     * prices and reads them; **Manager +1 of its own** for `ConstructionVariationApprove`, which agrees the
     * employer's money and writes the schedule, and which the policy also requires to write an approved
     * variation in; CEO +0 of its own, inheriting all five.
     *
     * Phase 4c, the four claim-and-certificate permissions, and this is the run where the shape of the module is
     * clearest in the numbers: Employee **+0** — a certificate is a commercial instrument and a site engineer has
     * no business in one; Accountant +2 to prepare claims and draft certificates; **Manager +1 of its own** for
     * `ConstructionCertificateCertify`, which starts a payment period and creates an entitlement the other party
     * will enforce; **CEO +1 of its own** for `ConstructionCertificateInvoice`, the act that moves the figure into
     * the books.
     *
     * Phase 5e, one permission: **`ConstructionVarianceAccept` on Manager**. Reading the three-way match report rides
     * on `ConstructionCommitmentView` — it is the same screenful of facts about the same orders — but accepting a
     * variance puts money on a job that nobody ordered at that figure, with a name against it. §5 puts the control at
     * acceptance rather than at payment, so this grant *is* the control.
     *
     * Phase 5d, one permission: **`ConstructionInvoiceAllocate` on Accountant**, inherited upward. Employee gains
     * nothing — coding a supplier invoice is a commercial act, and the person who signed the delivery note is not the
     * person who knows which code the company prices its next tender from. It is its own name because it is the act
     * that answers §5's "single most likely silent failure in the module": an invoice posted with no allocation leaves
     * the accounts perfectly correct and the job under-costed.
     *
     * Phase 5c, the three goods-receipt permissions: **Employee +2, one of them a record-and-post grant** — the
     * storeman signs the delivery note and is the only person who knows what actually arrived, so a receipt typed by
     * the office from a note that reached it a week later is how a delivery comes to be recorded against the wrong
     * job. Accountant +2 for the same two. **Manager +1 of its own** for `ConstructionReceiptReverse`, which takes
     * cost off a job and puts commitment back on an order — two registers, so not site's.
     *
     * Phase 5b, the three requisition permissions, and this is the run where the shape of the suite changes:
     * **Employee +2, one of them a `create`** — the first and only one in the construction suite. The demand
     * document exists because the demand comes from the people who need the material, and a requisition raised only
     * by the commercial office is a purchase order with an extra step. Accountant +2 to raise and read them;
     * **Manager +1 of its own** for `ConstructionRequisitionApprove`, which agrees the need is real and still
     * commits nothing — the money moves at `ConstructionCommitmentIssue`, two grants later.
     *
     * Manager and CEO gain **two** rather than three, and working out why is the useful part: the roles are separate
     * leaves rather than a chain, so neither inherits Employee's `ConstructionRequisitionCreate`. They get the two
     * the Accountant has plus their own approval — a manager who needs to raise a request holds the Accountant's
     * grant, not the site engineer's.
     *
     * Phase 5a, the five commitment permissions: Employee +1 for view, because "has the rebar been ordered" is a
     * site question and an invisible answer produces a second order for it; Accountant +2 to raise and price one;
     * **Manager +2 of its own** for `ConstructionCommitmentApprove` and `ConstructionCommitmentIssue`, which are two
     * decisions rather than one — approving spends the company's money, issuing commits it to a supplier and is what
     * puts the figure on the cost report; **CEO +1 of its own** for `ConstructionCommitmentClose`, which writes off
     * money somebody committed and needs a name against it.
     *
     * Phase 6b, the three back-charge permissions, on the shape the certificate already set: **Accountant +2**
     * (`View`, `Update`) because raising a back-charge and serving the notice is the surveyor's ordinary
     * administration; **Manager +1 of its own** for `ConstructionBackChargeApply`, which takes money off another
     * company's payment. `Apply` also carries agreeing and withdrawing, and that is the part worth defending: settling
     * at 180,000 against a notice of 240,000 gives away 60,000 of a recovery the company was entitled to, which is the
     * same shape of decision as releasing retention. A charge one person can raise and drop is a charge nobody has to
     * justify. **Employee +0** — site reports the incident; the charge is a commercial document.
     *
     * Manager and CEO gain three: the Accountant's two plus the apply grant.
     *
     * Phase 6a, the three compliance permissions, and the split between them is the decision: **Accountant +2**
     * (`View`, `Update`) because the commercial office files insurance certificates and reads them — and filing and
     * verifying are deliberately the same grant, since splitting them produces a register full of documents nobody has
     * looked at, which is exactly what `verified_at` exists to distinguish. **Manager +1 of its own** for
     * `ConstructionComplianceOverride`, which does two things a filing clerk should not: certifies a payment past
     * lapsed cover, and waives a requirement for good. **Employee +0** — a site engineer has no use for a
     * subcontractor's policy schedule, and the one construction question site does ask about compliance ("may we let
     * them start") is answered by the register's owner, not by the register.
     *
     * Manager and CEO therefore gain **three**: the Accountant's two plus the override.
     *
     * Phase 4d, one permission: **`ConstructionRetentionRelease` on Manager**. Reading the ledger rides on
     * `ConstructionCertificateView` — same audience, same screenful — but releasing hands back money the contract
     * entitled the company to hold, which on a job of any size is the largest single payment decision anybody
     * makes, and forfeiting takes money the other party earned.
     *
     * Phase 7a, the three labour permissions, and the split is between filing people and pricing their time.
     * **Employee +1** (`ConstructionLabourView`): a site engineer reads the gang list and the rates their job is being
     * charged at, and the second one is asked at exactly the moment somebody queries a week's cost. **Accountant +2**
     * (`View`, `Update`) — the commercial office maintains the trade list and the worker register. **Manager +1 of its
     * own** for `ConstructionLabourRateSet`, and that is the one worth defending: a company-default rate revised by ten
     * per cent restates the labour cost of everything booked from that date, on every job at once. §7.2's dated table
     * means the revision cannot rewrite the past; this grant is who may make it at all.
     *
     * Manager and CEO therefore gain **three**: the Accountant's two plus the rate grant.
     *
     * Phase 7b, two more: **`ConstructionLabourRecord` on Employee and Accountant** — recording a day's work is
     * site's, the same argument as the requisition and the goods receipt, because the ganger is the only person who
     * knows who turned up; and **`ConstructionLabourApprove` on Manager**, because approving is what books the cost and
     * freezes the rate the day was costed at. So Employee +1, Accountant +1, Manager +2 (the inherited record grant
     * plus its own approve), CEO +2.
     *
     * **Reversing booked labour gained no name**, and that is the decision worth recording: `ConstructionCostReverse`
     * already governs backing a posted entry out of the ledger, and a labour reversal is exactly that — twice, since
     * §7.3's burden is its own entry. A fifth labour permission would have been a fifth row in every role form for a
     * decision somebody already holds.
     *
     * Phase 7c, four plant permissions, and the shape mirrors labour with **one deliberate difference: no separate
     * rate permission.** A labour rate is a five-tier dated ladder whose company default reaches every job at once, so
     * `ConstructionLabourRateSet` earns its own name; a plant rate is one number on one machine, set when it joins the
     * fleet by the same person who registers it, in the same screen. A fifth name there would be a fifth row in every
     * role form for a decision nobody makes separately.
     *
     * **Employee +2** (`ConstructionPlantView`, `ConstructionPlantLog`): whether the excavator worked, stood idle or sat
     * on standby is only knowable by somebody who was there, which is the same argument as the goods receipt and the
     * site sheet. **Accountant +3** — the same two plus `ConstructionPlantUpdate`, the fleet register and its rates.
     * **Manager +4**: the Accountant's three plus `ConstructionPlantApprove`, which charges the job internal hire on an
     * owned machine and fixes the figure a supplier's invoice is checked against on a hired one. CEO +4.
     *
     * Phase 8b, **one** permission: `ConstructionMaterialIssue` on Employee and Accountant, inherited upward. One name
     * covering the docket *and* the posting, exactly as `ConstructionReceiptRecord` does for a delivery — the storeman
     * signs the paper and the stock moves in the same act, and splitting them would leave a queue of dockets whose
     * material has physically gone. Reading the register rides on `ConstructionReceiptView`, because receiving into a
     * store and issuing back out are the same person's job on the same screenful; reversing rides on
     * `ConstructionCostReverse`. So +1 to every role.
     *
     * Phase 9a, the first permissions of a **new module** — `construction_field`, whose group is `ConstructionField`.
     * Three names, and the split is the one §13's clock demands. **Employee +2** (`View`, `Update`): raising an event
     * and serving notice of it is site's, and this is the third create grant site staff hold after the requisition, the
     * goods receipt and the site sheet — "the people who watch an access being blocked are on site, and an event they
     * cannot record is an event nobody records", which fails in silence. **Accountant +2**, the same two.
     * **Manager +1 of its own** for `ConstructionDelayDetermine`: awarding days moves the completion date and decides
     * whether liquidated damages can be levied at all, so it is kept away from whoever raised the claim. CEO +3.
     *
     * Phase 9b, the site diary's three: **Employee +2** (`View`, `Update`) — the diary is a record of what happened on
     * site, written by somebody who was there, and it is the fourth create grant site staff hold in this suite.
     * **Accountant +2**, the same two. **Manager +1 of its own** for `ConstructionDailyLogApprove`, which §18.2 lists:
     * approval locks the day and turns it into evidence — "an editable site diary is not evidence" — and that is not
     * the same act as writing it down. The same grant carries reopening, because whoever may sign a day off is who may
     * unsign it. CEO +3.
     *
     * Phase 9c, the diary's deliveries and photographs: **no permissions at all**, and that is the entry worth reading.
     * Both are children of the diary and ride on `ConstructionDailyLogUpdate` — a docket and a photograph are what
     * writing a diary consists of. Promoting a photograph into the ISO 19650 register asks for
     * `ConstructionDocumentCreate`, which already exists and belongs to the register rather than to the diary: whoever
     * may write a diary is not automatically whoever may put a container in the register, and that gate is the only
     * thing keeping thirty thousand site photographs out of it.
     *
     * Phase 9d, RFIs, **two names rather than three**. **Employee +2** (`ConstructionRfiView`,
     * `ConstructionRfiUpdate`): the person who cannot build without an answer is the person standing in front of the
     * problem, and an RFI they cannot raise is a question asked by telephone and unprovable afterwards — the fifth
     * create grant site staff hold. **Accountant +2**, the same two, inherited upward, so Manager and CEO gain the same
     * two and nothing of their own.
     *
     * *Recording the answer is deliberately not a third name.* The answer arrives by email and somebody transcribes it,
     * which is clerical rather than an approval — and a permission there would leave answers sitting in an inbox while
     * the register still says the question is open, which is worse than the risk it guards. What genuinely needed
     * separating already is: raising the delay event behind an RFI's stated time impact asks for
     * `ConstructionDelayUpdate`, because serving notice on the employer is not the same act as asking a question.
     *
     * Phase 9e, submittals, **two names again and for the same reason**. **Employee +2**
     * (`ConstructionSubmittalView`, `ConstructionSubmittalUpdate`), **Accountant +2**, inherited upward. Recording a
     * reviewer's return rides on the same grant as submitting: the stamped drawing arrives from the Architect and
     * somebody files it, which is transcription rather than an approval — and a permission there would leave stamped
     * drawings in a drawer while the register says the item is still out for review. A register nobody believes about
     * what is outstanding has no purpose. The act that needed separating already was: notifying a reviewer's overrun
     * asks for `ConstructionDelayUpdate`.
     *
     * Phase 9f, punch lists, **two names again — and the third one would have been the tempting mistake.** **Employee
     * +2** (`ConstructionPunchView`, `ConstructionPunchUpdate`), **Accountant +2**, inherited upward. Closing a punch
     * item releases part of §11's AIA holdback, so it is the one act here that moves money — and it is protected
     * *structurally* rather than by a `ConstructionPunchClose` grant: an item closes only when a re-inspection is
     * recorded with a passing result. That is stronger than a permission, because a permission can be granted to the
     * person who caused the defect and a missing passed inspection cannot be granted away at all.
     *
     * Phase 9g, the programme, and **the first place in this module where a third name earns its place.** Reading and
     * maintaining the programme is planning work; **recording progress is separate**, because percent complete and
     * actual dates are what §14's earned value and every schedule index are computed from — and the person who reports
     * 80% is not usually the person who owns the consequence of it being 60%. Progress claimed against a programme is
     * the oldest optimism in construction.
     *
     * **Employee +2** (`ConstructionProgrammeView`, `ConstructionProgrammeProgress`): the look-ahead is site's document
     * and site reports its own progress against it. **Accountant +2**, the same two. **Manager +1 of its own** for
     * `ConstructionProgrammeUpdate` — the programme is the document a claim is measured against, so editing it is not
     * the same act as reporting against it. CEO +3.
     *
     * Note what is deliberately not a fourth name: the baseline. It is the *accepted* programme, and this application
     * does not accept programmes — it stores what P6 exported. Guarding a column only an import writes would be
     * theatre.
     *
     * Phase 10a, the first permissions of the **`construction_qhse`** module, whose group is `ConstructionQhse`. Six
     * names, and one of them is the reason §17.1 exists at all.
     *
     * **Employee +3** (`ConstructionItpView`, `ConstructionInspectionView`, `ConstructionInspectionUpdate`): requesting
     * and recording inspections is site's — the fifth create grant site staff hold in this suite — because the person
     * who can see that the rebar is ready is standing in front of it, and an inspection they cannot request is a hold
     * point that gets passed by telephone. Reading the ITP is theirs too: the point of the document is that the people
     * doing the work know what will be inspected and when. **Accountant +3**, the same three.
     *
     * **Manager +3 of its own**: `ConstructionItpUpdate`, `ConstructionItpApprove` and — the important one —
     * **`ConstructionInspectionRelease`**. §17.1: "the whole function of a hold point is that work may not proceed past
     * it." Releasing one authorises the next operation to start, and it is the act a certification body audits;
     * *recording* that an inspection passed is not the same decision, and on a site where they are the same person the
     * hold point has no function. Approving an ITP is separated for an unusually external reason: the signature on it is
     * a statement to a third party about how the work will be controlled. CEO +6.
     *
     * Requesting and recording are deliberately one grant: the request goes out and the result comes back to the same
     * engineer, and splitting them would leave results in a notebook while the register says the inspection is awaited.
     *
     * Phase 10b, non-conformance, three names. **Employee +2** (`ConstructionNcrView`, `ConstructionNcrUpdate`) —
     * anybody who can see the work is wrong should be able to say so, and a register that made that difficult would
     * record the nonconformities somebody remembered to mention. **Accountant +2**, the same two. **Manager +1 of its
     * own** for `ConstructionNcrDisposition`, which §17.2 calls "the field that decides whether money changes hands":
     * *use as is* and *concession requested* accept work below specification, which is the client giving something up,
     * and that is not a call for whoever noticed the defect. CEO +3.
     *
     * **Proposing a deduction rides on the disposition grant rather than earning a fourth name**, because the two
     * decisions are made in the same conversation and *the proposal withholds nothing*. The act that moves money is on
     * the far side of the module boundary, taken by whoever signs the certificate — which is why there is no
     * `ConstructionNcrDeduct` at all.
     *
     * Phase 10c, §17.4's one actions table, three names. **Employee +2** (`ConstructionActionView`,
     * `ConstructionActionUpdate`) — the actions register is the working list and site is who works from it.
     * **Accountant +2**, the same two. **Manager +1 of its own** for `ConstructionActionVerify`. CEO +3.
     *
     * The verify grant is the same argument §16.4 makes about a passed re-inspection and §17.2 about a verified NCR:
     * "done" is the assignee's claim and "verified" is somebody else's confirmation. One grant for both would let
     * whoever caused a finding close it — and an actions register nobody believes is a register nobody reads.
     *
     * Raising and completing stay one grant, because on a real site the person who writes the action down and the person
     * who reports it done are frequently the same, and splitting them would leave finished work showing as outstanding
     * for want of a second click.
     *
     * Phase 10d, incidents, three names — and **`ConstructionIncidentReport` is the widest grant in this entire module,
     * deliberately.** §17.3's leading indicator is near misses per lost-time injury, and a permission that made
     * reporting hard would suppress exactly the number it most needs: a site reporting no near misses is not a safe
     * site, it is a quiet one. **Employee +2** (`View`, `Report`), **Accountant +2**, the same two.
     *
     * **Manager +1 of its own** for `ConstructionIncidentInvestigate`, which closes an incident and tells the authority.
     * Closing asserts that the cause is understood and the lesson recorded, and the person who was involved is not the
     * person to conclude that; the authority report is a statutory duty with somebody's name against it. CEO +3.
     *
     * Phase 10e, permits to work, three names — and **`ConstructionPermitIssue` is the sharpest segregation in this
     * module.** Issuing a permit authorises high-risk work: hot work in a finished building, entry into a confined
     * space, a lift over a live road. The person who wants to do the work is the last person who should decide it is
     * safe to, and every permit-to-work regime is built on that separation.
     *
     * **Employee +2** (`ConstructionPermitView`, `ConstructionPermitRequest`): a supervisor who cannot raise a permit is
     * a supervisor whose gang works without one. **Accountant +2**, the same two. **Manager +1 of its own** for
     * `ConstructionPermitIssue`, which also carries resuming and closing out, because both are assertions about safety.
     * CEO +3.
     *
     * *Suspending is deliberately on the wide grant*, not the issuing one: a permit that can only be suspended by
     * whoever issued it is a permit that stays live while somebody goes looking for them.
     *
     * Phase 10f, the induction register, competencies and toolbox talks — **two names, and the update grant is
     * deliberately wide.** **Employee +2** (`ConstructionPersonnelView`, `ConstructionPersonnelUpdate`), **Accountant
     * +2**, inherited upward, and **Manager gains nothing of its own**.
     *
     * Putting somebody on the register, inducting them, recording their tickets and writing up a toolbox talk is *gate
     * work*: it happens at seven in the morning, done by whoever is at the gate, for people who arrived that day. A
     * permission that made it a supervisor's job would produce a register that lags the site by a week — and a register
     * that lags is one nobody trusts to say who is cleared to work.
     *
     * Toolbox talks share the grant rather than earning their own, because both are the same job done by the same person
     * at the same moment, and a second name would only mean one of the two got filled in. `View` is separate because
     * this register holds names, phone numbers and medical certificates — the most personal data in the module.
     *
     * Phase 11a, the GL posting service — **one name, and it is Manager's rather than Accountant's.** **Employee and
     * Accountant gain nothing; Manager +1** (`ConstructionGlPost`), inherited upward to CEO.
     *
     * §4.1 makes this the only act in the construction suite that writes into another module's ledger: it creates
     * journal entries in the general ledger, they appear in the trial balance, and they change the company's reported
     * cost. The surveyor records and approves the cost; this decides what of it reaches the books and against which
     * accounts, and a posting made by the same person who approved the cost is a posting nobody checked — the same
     * segregation the journal-entry powers already keep between whoever writes an entry and whoever posts it.
     *
     * **Reversing rides on it and nominating the control accounts rides on it too.** Whoever may put a figure in the
     * books is who may take it back out; a second grant would leave a wrong posting sitting there while somebody went
     * looking for the person who held it, and the control on a reversal is the required reason rather than a name.
     * Nominating accounts is one screenful of decisions taken once at implementation by whoever owns the chart, which is
     * §18.2's test for when a name is *not* worth having.
     *
     * Phase 6 of the reports plan, the report builder — **two names, and only one of them is granted to
     * anybody.** **Employee gains nothing; Accountant +1** (`ReportBuild`), inherited upward to Manager and
     * CEO. `ReportShare` is granted to no role and therefore moves no count.
     *
     * `ReportBuild` sits with `ReportView` because composing a report is the same job as reading one, done
     * with a different shape: anybody trusted to read the ledger is trusted to ask it a question. An Employee
     * is not — the reports they see are their own payslip and their own leave, and neither is a question they
     * compose.
     *
     * `ReportShare` is the one this phase argued about and it is Administrator's alone. It makes a definition
     * visible to the whole company, and item 6 names the failure exactly: "a company-wide custom report over
     * payslips is a payroll leak, and it is one careless toggle away". Worth being precise about what the
     * grant is protecting, because it is *not* the figures — reading a shared definition resolves its subject
     * through the reader's own module and permission gates, so a shared payslip report shows a person nothing
     * they could not already open. What it protects is the company's own list of reports: an unshared
     * definition is somebody's working note, and a hub filling up with forty of them is a hub nobody reads.
     *
     * Phase 8 of the reports plan, scheduled reports — **five names, four of them granted.** **Employee gains
     * nothing; Accountant +3** (`ReportScheduleView`, `ReportScheduleCreate`, `ReportScheduleUpdate`),
     * inherited upward, **and CEO +1** (`ReportScheduleDelete`) with the other deletes.
     * `ReportSendExternal` is granted to no role and therefore moves no count.
     *
     * Keeping a schedule sits with `ReportBuild` on the same reasoning: anybody trusted to read the ledger is
     * trusted to have it emailed to them. What stops that being a way to send *somebody else's* rows is not a
     * permission but the policy — update and delete are the owner's own, because a schedule renders with its
     * owner's access and editing its recipient list would be sending their figures to a list they never
     * agreed to.
     *
     * `ReportSendExternal` is Administrator's alone, and it is the one name in this phase that is not a CRUD
     * verb. An address matching nobody in the company is a report *leaving* the company — nobody has to log in
     * to read it and nothing in the application records who forwarded it — so the decision belongs with the
     * person who can already see everything, and every external delivery is recorded on the delivery row.
     *
     * @var array<string, int>
     */
    private const EXPECTED = [
        'Employee' => 69,
        'Accountant' => 153,
        // 196 since 2026-09-21: InvoiceOverrideCreditLimit — docs/erpnext-gap-plan.md §4 item 5.
        // 197 since 2026-10-26: ConstructionDocumentRestrictedView, beside Publish — construction plan §15.2.
        'Manager' => 197,
        // 217 since 2026-09-21: CEO composes on Manager, so InvoiceOverrideCreditLimit reaches here too;
        // 218 since 2026-10-26 for the same reason.
        'CEO' => 218,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'rolegrants@test.local'));
        $this->setCurrentTenant();

        (new RoleSeeder)->run();
    }

    /** @return array<int, string> */
    private function permissionsOf(string $role): array
    {
        return Role::query()
            ->with('permissions')
            ->where('name', $role)
            ->firstOrFail()
            ->permissions
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    public function test_every_role_holds_exactly_the_permissions_it_is_meant_to(): void
    {
        foreach (self::EXPECTED as $role => $count) {
            $this->assertCount($count, $this->permissionsOf($role), "{$role} changed size");
        }
    }

    /** Administrator is defined as "everything", so it needs no declarations and never goes stale. */
    public function test_administrator_holds_every_permission(): void
    {
        $this->assertSame(
            Permission::query()->pluck('name')->sort()->values()->all(),
            $this->permissionsOf('Administrator'),
        );
    }

    /**
     * The composition a module cannot express.
     *
     * Manager is Accountant plus approvals; CEO is Manager plus deletions. A module contributes only its
     * additions at each rung, so if this chain broke, the two senior roles would silently lose everything the
     * junior one holds — and every approval screen would 403 for the people meant to use it.
     */
    public function test_manager_and_ceo_are_supersets_of_the_role_below(): void
    {
        $accountant = $this->permissionsOf('Accountant');
        $manager = $this->permissionsOf('Manager');
        $ceo = $this->permissionsOf('CEO');

        $this->assertSame([], array_diff($accountant, $manager), 'Manager lost something Accountant holds');
        $this->assertSame([], array_diff($manager, $ceo), 'CEO lost something Manager holds');

        // And each rung genuinely adds something, or the composition is decorative.
        $this->assertNotEmpty(array_diff($manager, $accountant));
        $this->assertNotEmpty(array_diff($ceo, $manager));
    }

    /**
     * Segregation of duties: the Accountant records and does not approve, post or reverse.
     *
     * The reason the middle three roles exist at all, and the one property of this file worth reading if
     * something here fails.
     */
    public function test_the_accountant_cannot_approve_post_or_reverse(): void
    {
        $accountant = $this->permissionsOf('Accountant');

        foreach (['JournalEntryApprove', 'JournalEntryPost', 'JournalEntryReverse', 'JournalEntryReject'] as $name) {
            $this->assertNotContains($name, $accountant, "the Accountant must not hold {$name}");
            $this->assertContains($name, $this->permissionsOf('Manager'), "the Manager must hold {$name}");
        }
    }

    /**
     * Deleting a ledger transaction is Administrator-only, even for the CEO.
     *
     * The CEO corrects the books by reversing, which leaves both rows on the ledger. This was a comment in
     * the seeder and is now an assertion, because it is the kind of decision a later grant undoes by accident.
     */
    public function test_not_even_the_ceo_deletes_a_journal_entry(): void
    {
        $this->assertNotContains('JournalEntryDelete', $this->permissionsOf('CEO'));
        $this->assertContains('JournalEntryDelete', $this->permissionsOf('Administrator'));
    }

    /**
     * A sales pipeline is not something every member of staff has.
     *
     * The one decision no module can declare — an absence — so it is asserted here rather than left to a
     * comment. See RoleSeeder's docblock.
     */
    public function test_the_employee_role_has_no_crm_access(): void
    {
        $employee = $this->permissionsOf('Employee');

        foreach ($employee as $name) {
            $this->assertStringNotContainsString('Lead', $name, 'CRM reached the Employee role');
        }
    }

    /** A module may only grant what it declares — the check that replaces the central list's implicit one. */
    public function test_no_module_grants_a_permission_it_does_not_own(): void
    {
        $manifest = ModuleManifest::all();
        $groupOwner = [];

        foreach ($manifest['permission_groups'] ?? [] as $module => $groups) {
            foreach ($groups as $group) {
                $groupOwner[$group] = $module;
            }
        }

        $owner = [];

        foreach ($manifest['permissions'] ?? [] as $permission) {
            $owner[$permission['name']] = $groupOwner[$permission['group']] ?? null;
        }

        $problems = [];

        foreach (ModuleManifest::manifestPaths() as $module => $path) {
            foreach ((require $path)['role_grants'] ?? [] as $role => $names) {
                foreach ($names as $name) {
                    if (($owner[$name] ?? null) !== $module) {
                        $problems[] = "{$module} grants {$name} to {$role} but does not declare it";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * The grants actually come from the manifests.
     *
     * Guards the guard: every assertion above would still pass if somebody restored the literal lists to
     * RoleSeeder, and the central edit would be back with the suite green.
     */
    public function test_the_grants_are_declared_by_modules_rather_than_by_the_seeder(): void
    {
        $declaring = [];

        foreach (ModuleManifest::manifestPaths() as $module => $path) {
            if ((require $path)['role_grants'] ?? [] !== []) {
                $declaring[] = $module;
            }
        }

        $this->assertGreaterThanOrEqual(15, count($declaring), 'the manifests stopped carrying the grants');

        $source = file_get_contents((new \ReflectionClass(RoleSeeder::class))->getFileName());

        // The seeder names the four composed roles and Administrator, and no permission at all except the
        // four it asserts the Accountant must not hold — which live in this test, not there.
        $this->assertStringNotContainsString("'PayslipView'", $source, 'a literal grant is back in RoleSeeder');
        $this->assertStringNotContainsString("'AccountView'", $source, 'a literal grant is back in RoleSeeder');
    }
}
