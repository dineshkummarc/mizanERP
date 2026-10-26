<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\DocumentRevision;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\NamingConvention;
use App\Modules\Construction\Models\Transmittal;
use App\Modules\Construction\Models\TransmittalRecipient;
use App\Modules\Construction\Services\DocumentStateMachine;
use App\Modules\Core\Models\CompanyModule;
use App\Support\TenantStorage;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The ISO 19650 document register — §15, Phase 1d.
 *
 * The standard asks for four things: a unique standard name per container, a suitability status, controlled
 * revisions so a superseded one is not in use, and the four-state machine with an approval gate. What is tested
 * hardest here is the machine, because §15 puts it in a service specifically so it cannot be walked past — a
 * rule enforced in a Filament form is bypassed by every import, command and API that follows.
 *
 * The sharpest rule in that section, and the one with a real defect behind it: **published is the only state a
 * construction-issue drawing may be in.** An inspection, an RFI answer or a punch item referencing a
 * work-in-progress drawing is a defect waiting to be built.
 */
class ConstructionDocumentRegisterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private DocumentStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'docs@test.local'));
        $this->setCurrentTenant();

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->machine = app(DocumentStateMachine::class);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'job_id' => $this->job->getKey(),
            'information_container_id' => 'TWR-XYZ-ST-01-DR-S-0100',
            'title' => 'Ground floor slab layout',
        ], $attributes));
    }

    private function revise(Document $document, string $revision, array $attributes = []): DocumentRevision
    {
        return $this->machine->issueRevision($document, array_merge([
            'revision' => $revision,
            'suitability_code' => 'S2',
        ], $attributes));
    }

    // ---------------------------------------------------------------- naming

    /**
     * The identifier is assembled from the fields, in the convention's own order and separator.
     *
     * Each field is its own column so the name can be both re-assembled *and* validated — one concatenated
     * string would make "which containers use originator XYZ" unanswerable, which is the query a document
     * controller runs when a consultant is replaced.
     */
    public function test_the_identifier_assembles_from_the_naming_fields(): void
    {
        $convention = NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'ISO 19650-2 (UK)',
            'separator' => '-',
        ]);

        $document = $this->document([
            'naming_convention_id' => $convention->getKey(),
            'project_code' => 'TWR',
            'originator_code' => 'XYZ',
            'functional_code' => 'ST',
            'spatial_code' => '01',
            'form_code' => 'DR',
            'discipline_code' => 'S',
            'container_number' => '0100',
        ]);

        $this->assertSame('TWR-XYZ-ST-01-DR-S-0100', $document->assembleIdentifier());
    }

    public function test_a_convention_may_reorder_and_reseparate_the_fields(): void
    {
        $convention = NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'Discipline first',
            'separator' => '_',
            'field_order' => ['discipline_code', 'project_code', 'container_number'],
        ]);

        $document = $this->document([
            'naming_convention_id' => $convention->getKey(),
            'project_code' => 'TWR',
            'discipline_code' => 'S',
            'container_number' => '0100',
        ]);

        $this->assertSame('S_TWR_0100', $document->assembleIdentifier());
    }

    /**
     * A project that has issued no code list allows anything.
     *
     * The alternative blocks every upload until somebody types out seven code lists, which is a rule people
     * work around on day one — and §15 is explicit that hardcoding the codes makes the module unusable on job
     * number two.
     */
    public function test_a_convention_with_no_code_list_allows_any_value(): void
    {
        $convention = NamingConvention::create(['job_id' => $this->job->getKey(), 'name' => 'Loose']);

        $this->assertTrue($convention->allows('discipline_code', 'S'));
        $this->assertTrue($convention->allows('discipline_code', 'anything'));
        $this->assertTrue($convention->allowsSuitability('S2'));
    }

    public function test_a_convention_with_a_code_list_refuses_a_value_outside_it(): void
    {
        $convention = NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'Strict',
            'code_lists' => ['discipline_code' => ['A', 'S', 'M', 'E']],
            'suitability_codes' => ['S0', 'S1', 'S2', 'S3', 'S4'],
        ]);

        $this->assertTrue($convention->allows('discipline_code', 'S'));
        $this->assertFalse($convention->allows('discipline_code', 'Q'));
        $this->assertTrue($convention->allowsSuitability('S2'));
        $this->assertFalse($convention->allowsSuitability('for construction'));
    }

    /** Suitability is a string, so an AIA-land project's own vocabulary works without a schema change. */
    public function test_suitability_is_not_an_enum(): void
    {
        $convention = NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'AIA',
            'suitability_codes' => ['for approval', 'for construction', 'as-built'],
        ]);

        $this->assertTrue($convention->allowsSuitability('for construction'));

        $document = $this->document();
        $revision = $this->revise($document, 'A', ['suitability_code' => 'for construction']);

        $this->assertSame('for construction', $revision->suitability_code);
    }

    /** One container id per job, and the same one may legitimately exist on a different job. */
    public function test_the_container_id_is_unique_per_job(): void
    {
        $this->document();

        $other = Job::create(['code' => 'J-2', 'name' => 'Bridge']);
        $onOther = Document::create([
            'job_id' => $other->getKey(),
            'information_container_id' => 'TWR-XYZ-ST-01-DR-S-0100',
            'title' => 'Same name, different job',
        ]);

        $this->assertTrue($onOther->exists);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->document();
    }

    // ---------------------------------------------------------------- revisions

    public function test_issuing_a_revision_makes_it_current_and_supersedes_the_last(): void
    {
        $document = $this->document();

        $first = $this->revise($document, 'P01');
        $this->assertSame($first->getKey(), $document->fresh()->current_revision_id);
        $this->assertSame(DocumentRevision::STATUS_ISSUED, $first->status);

        $second = $this->revise($document, 'P02');

        $this->assertSame($second->getKey(), $document->fresh()->current_revision_id, 'the register still shows the old sheet');
        $this->assertSame(DocumentRevision::STATUS_SUPERSEDED, $first->fresh()->status);
    }

    /**
     * A re-issue with an identical file is allowed and *reported*, not refused.
     *
     * It happens for real — a title block changes outside the file — and the sha256 is what lets a reviewer
     * skip it rather than re-check forty sheets. Refusing it would just make somebody rename the file.
     */
    public function test_an_unchanged_reissue_is_detectable(): void
    {
        $document = $this->document();

        $this->revise($document, 'P01', ['file_hash' => str_repeat('a', 64)]);
        $second = $this->revise($document, 'P02', ['file_hash' => str_repeat('a', 64)]);

        $this->assertTrue($this->machine->isUnchangedReissue($second));
    }

    public function test_a_changed_reissue_reads_as_changed(): void
    {
        $document = $this->document();

        $this->revise($document, 'P01', ['file_hash' => str_repeat('a', 64)]);
        $second = $this->revise($document, 'P02', ['file_hash' => str_repeat('b', 64)]);

        $this->assertFalse($this->machine->isUnchangedReissue($second));
    }

    /** With no hash the answer is "cannot tell", not a misleading false. */
    public function test_an_unhashed_reissue_says_it_cannot_tell(): void
    {
        $document = $this->document();

        $this->revise($document, 'P01');
        $second = $this->revise($document, 'P02');

        $this->assertNull($this->machine->isUnchangedReissue($second));
    }

    public function test_the_first_revision_has_nothing_to_compare_against(): void
    {
        $document = $this->document();
        $first = $this->revise($document, 'P01', ['file_hash' => str_repeat('a', 64)]);

        $this->assertNull($this->machine->isUnchangedReissue($first));
    }

    // ---------------------------------------------------------------- the state machine

    public function test_a_container_starts_at_work_in_progress(): void
    {
        $this->assertSame(Document::STATE_WIP, $this->document()->cde_state);
    }

    public function test_it_moves_forward_through_the_four_states(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');

        $this->machine->transition($document, Document::STATE_SHARED);
        $this->assertSame(Document::STATE_SHARED, $document->fresh()->cde_state);

        $this->machine->transition($document->fresh(), Document::STATE_PUBLISHED);
        $this->assertSame(Document::STATE_PUBLISHED, $document->fresh()->cde_state);

        $this->machine->transition($document->fresh(), Document::STATE_ARCHIVED);
        $this->assertSame(Document::STATE_ARCHIVED, $document->fresh()->cde_state);
    }

    /**
     * A published drawing may not quietly return to work in progress.
     *
     * Somebody is already building from it. The honest act is a new revision, and the refusal says so.
     */
    public function test_it_refuses_to_move_backwards(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');
        $this->machine->transition($document, Document::STATE_SHARED);
        $this->machine->transition($document->fresh(), Document::STATE_PUBLISHED);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Issue a new revision instead of moving it back/');

        $this->machine->transition($document->fresh(), Document::STATE_WIP);
    }

    /**
     * The approval gate: sharing while approval is pending is refused, with the reason.
     *
     * Sharing is what puts a drawing in front of other disciplines to design against, so doing it before the
     * gate clears means people co-ordinate to something that may still be rejected.
     */
    public function test_it_refuses_to_share_while_approval_is_pending(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01', ['approval_status' => DocumentRevision::APPROVAL_PENDING]);

        $this->expectExceptionMessageMatches('/awaiting approval/');

        $this->machine->transition($document->fresh(), Document::STATE_SHARED);
    }

    public function test_it_refuses_to_publish_a_rejected_revision(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');
        $this->machine->transition($document, Document::STATE_SHARED);

        $document->currentRevision->update(['approval_status' => DocumentRevision::APPROVAL_REJECTED]);

        $this->expectExceptionMessageMatches('/was rejected/');

        $this->machine->transition($document->fresh(), Document::STATE_PUBLISHED);
    }

    public function test_it_refuses_to_publish_a_container_with_no_revision(): void
    {
        $document = $this->document();
        $this->machine->transition($document, Document::STATE_SHARED);

        $this->expectExceptionMessageMatches('/no revision to publish/');

        $this->machine->transition($document->fresh(), Document::STATE_PUBLISHED);
    }

    /** Archiving is reachable from anywhere: a container abandoned at WIP is archived, never deleted. */
    public function test_anything_may_be_archived(): void
    {
        $document = $this->document();

        $this->machine->transition($document, Document::STATE_ARCHIVED);

        $this->assertSame(Document::STATE_ARCHIVED, $document->fresh()->cde_state);
    }

    public function test_archived_is_terminal(): void
    {
        $document = $this->document();
        $this->machine->transition($document, Document::STATE_ARCHIVED);

        $this->assertFalse($this->machine->canTransition($document->fresh(), Document::STATE_PUBLISHED));
    }

    /**
     * The sharpest rule in §15: only a published container may be issued for construction.
     *
     * An inspection, an RFI answer or a punch item referencing a work-in-progress drawing is a defect waiting
     * to be built, so everything downstream asks this question rather than reading the state itself.
     */
    public function test_only_a_published_container_is_issuable_for_construction(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');

        $this->assertFalse($document->isIssuableForConstruction());

        $this->machine->transition($document, Document::STATE_SHARED);
        $this->assertFalse($document->fresh()->isIssuableForConstruction());

        $this->machine->transition($document->fresh(), Document::STATE_PUBLISHED);
        $this->assertTrue($document->fresh()->isIssuableForConstruction());
    }

    /** The revision records the state it was issued at, so the register can answer "what was published then". */
    public function test_a_revision_records_the_state_it_was_issued_at(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');
        $this->machine->transition($document, Document::STATE_SHARED);

        $this->assertSame(Document::STATE_SHARED, $document->fresh()->currentRevision->cde_state_at_issue);
    }

    public function test_the_issuable_scope_excludes_superseded_containers(): void
    {
        $published = $this->document();
        $this->revise($published, 'P01');
        $this->machine->transition($published, Document::STATE_SHARED);
        $this->machine->transition($published->fresh(), Document::STATE_PUBLISHED);

        $replaced = $this->document(['information_container_id' => 'TWR-XYZ-ST-01-DR-S-0101']);
        $this->revise($replaced, 'P01');
        $this->machine->transition($replaced, Document::STATE_SHARED);
        $this->machine->transition($replaced->fresh(), Document::STATE_PUBLISHED);
        $replaced->update(['superseded_by_document_id' => $published->getKey()]);

        $this->assertSame(
            ['TWR-XYZ-ST-01-DR-S-0100'],
            Document::query()->issuable()->pluck('information_container_id')->all(),
        );
    }

    // ---------------------------------------------------------------- models and previews

    /** A model is registered so the register is complete, and never opened. §15.1 draws that line. */
    public function test_a_model_container_is_never_previewable(): void
    {
        $document = $this->document(['document_type' => Document::TYPE_MODEL]);
        $this->revise($document, 'P01', ['file_mime' => 'application/octet-stream']);

        $this->assertTrue($document->isModel());
        $this->assertFalse($document->fresh()->isPreviewable());
    }

    public function test_a_pdf_or_image_previews_in_the_browser(): void
    {
        $pdf = $this->document();
        $this->revise($pdf, 'P01', ['file_mime' => 'application/pdf']);
        $this->assertTrue($pdf->fresh()->isPreviewable());

        $image = $this->document(['information_container_id' => 'TWR-XYZ-ST-01-PH-S-0001']);
        $this->revise($image, 'P01', ['file_mime' => 'image/jpeg']);
        $this->assertTrue($image->fresh()->isPreviewable());
    }

    // ---------------------------------------------------------------- transmittals

    /**
     * A transmittal item points at the **revision**, not the document.
     *
     * A transmittal is the record of which version went out. Pointing at the document would make it say
     * something different after the next issue — and that record is exactly what somebody disputes later.
     */
    public function test_a_transmittal_records_the_revision_that_went_out(): void
    {
        $document = $this->document();
        $first = $this->revise($document, 'P01');

        $transmittal = Transmittal::create([
            'job_id' => $this->job->getKey(),
            'reference' => 'TR-001',
            'subject' => 'Structural issue 1',
        ]);
        $transmittal->items()->create(['document_revision_id' => $first->getKey()]);

        // A later revision must not change what the transmittal says.
        $this->revise($document, 'P02');

        $this->assertSame('P01', $transmittal->fresh()->items->first()->revision->revision);
    }

    /**
     * The chase list: notified and not acknowledged.
     *
     * The point of the recipients table — a transmittal nobody acknowledged is a drawing somebody will later
     * say they never received, and on a claim that argument is worth money.
     */
    public function test_outstanding_acknowledgements_are_the_chase_list(): void
    {
        $transmittal = Transmittal::create([
            'job_id' => $this->job->getKey(),
            'reference' => 'TR-001',
            'subject' => 'Structural issue 1',
        ]);

        $transmittal->recipients()->create([
            'name' => 'Acknowledged already',
            'role' => TransmittalRecipient::ROLE_ACTION,
            'notified_at' => now()->subDay(),
            'acknowledged_at' => now(),
        ]);
        $transmittal->recipients()->create([
            'name' => 'Still silent',
            'role' => TransmittalRecipient::ROLE_APPROVAL,
            'notified_at' => now()->subDay(),
        ]);
        // Never notified, so not yet a chase — sending is what starts the clock.
        $transmittal->recipients()->create([
            'name' => 'Not sent yet',
            'role' => TransmittalRecipient::ROLE_INFORMATION,
        ]);

        $outstanding = $transmittal->fresh()->outstandingAcknowledgements();

        $this->assertCount(1, $outstanding);
        $this->assertSame('Still silent', $outstanding->first()->displayName());
    }

    /** Only action and approval recipients owe anything; an information copy owes nothing. */
    public function test_only_action_and_approval_recipients_owe_a_response(): void
    {
        $transmittal = Transmittal::create([
            'job_id' => $this->job->getKey(),
            'reference' => 'TR-001',
            'subject' => 'Issue',
        ]);

        $action = $transmittal->recipients()->create(['name' => 'A', 'role' => TransmittalRecipient::ROLE_ACTION]);
        $approval = $transmittal->recipients()->create(['name' => 'B', 'role' => TransmittalRecipient::ROLE_APPROVAL]);
        $info = $transmittal->recipients()->create(['name' => 'C', 'role' => TransmittalRecipient::ROLE_INFORMATION]);

        $this->assertTrue($action->owesAResponse());
        $this->assertTrue($approval->owesAResponse());
        $this->assertFalse($info->owesAResponse());
    }

    /** Deleting a job takes its register with it. */
    public function test_deleting_a_job_removes_its_documents(): void
    {
        $document = $this->document();
        $this->revise($document, 'P01');

        $this->job->delete();

        $this->assertSame(0, Document::query()->where('job_id', $this->job->getKey())->count());
        $this->assertSame(0, DocumentRevision::query()->count());
    }

    // ---------------------------------------------------------- confidentiality

    /**
     * A restricted document's file streams only to somebody the policy lets view it — the construction
     * plan's §15.2, which named this as advisory rather than enforced. Membership stays the floor for every
     * other file, so a payslip or a receipt streams as it always has.
     */
    public function test_a_restricted_documents_file_streams_only_to_somebody_the_policy_lets_view_it(): void
    {
        // Written where the file controller reads: the company's own directory, as the uploads land.
        $root = TenantStorage::publicRoot($this->tenant);
        foreach (['construction/documents/slab.pdf', 'construction/documents/tender.pdf', 'payslips/january.pdf'] as $file) {
            File::ensureDirectoryExists(dirname("{$root}/{$file}"));
            File::put("{$root}/{$file}", '%PDF-'.basename($file));
        }

        $this->revise($this->document(), 'P01', ['file_path' => 'construction/documents/slab.pdf']);
        $restricted = $this->document([
            'information_container_id' => 'TWR-XYZ-ST-01-DR-S-0200',
            'title' => 'Tender build-up',
            'confidentiality' => Document::CONFIDENTIALITY_RESTRICTED,
        ]);
        $this->revise($restricted, 'P01', ['file_path' => 'construction/documents/tender.pdf']);

        // A site engineer holds ConstructionDocumentView; a manager holds RestrictedView beside Publish.
        $engineer = $this->makeUser('Employee', 'site@test.local');
        $manager = $this->makeUser('Manager', 'pm@test.local');
        $id = $this->tenant->getKey();

        $this->assertFalse($engineer->can('view', $restricted));
        $this->assertTrue($manager->can('view', $restricted));

        $this->actingAs($engineer)->get("/files/{$id}/construction/documents/slab.pdf")->assertOk();
        $this->actingAs($engineer)->get("/files/{$id}/construction/documents/tender.pdf")->assertForbidden();
        $this->actingAs($manager)->get("/files/{$id}/construction/documents/tender.pdf")->assertOk();
        $this->actingAs($engineer)->get("/files/{$id}/payslips/january.pdf")->assertOk();
    }
}
