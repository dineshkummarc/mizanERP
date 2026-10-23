<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers\DocumentsRelationManager;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\ContactDocument;
use App\Modules\Invoicing\Services\ContactDocumentExpiry;
use App\Notifications\ContactDocumentExpiring;
use App\Support\ExpiryThreshold;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Contracts and documents on the contact — docs/bexio-gap-plan.md §3.7.
 *
 * The expiry cases are Lifecycle's, on the other kind of document: warn once per threshold, keep
 * reporting an expired one, and let a renewal warn again.
 */
class ContactDocumentsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as CustomerStatementTest does. The expiry command
        // is TenantAware, so it makes the company current.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
        ]]);

        $this->admin = $this->makeUser('Administrator', 'sales@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();

        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
    }

    private function document(array $attributes = []): ContactDocument
    {
        return ContactDocument::create(array_merge([
            'contact_id' => $this->customer->getKey(),
            'kind' => 'contract',
            'title' => 'Supply agreement 2026',
        ], $attributes));
    }

    public function test_the_documents_tab_is_on_the_contact_and_files_one(): void
    {
        $this->assertContains(DocumentsRelationManager::class, ContactResource::getRelations());

        $tab = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $this->customer,
            'pageClass' => EditContact::class,
        ]);

        $tab->callAction(TestAction::make('create')->table(), [
            'kind' => 'contract',
            'title' => 'Supply agreement 2026',
            'number' => 'SA-2026-01',
            'issued_on' => '2026-10-01',
            'expires_on' => '2027-09-30',
        ]);

        $document = ContactDocument::query()->firstOrFail();
        $this->assertTrue($document->contact->is($this->customer));
        $this->assertSame($this->admin->getKey(), $document->uploaded_by);
        $this->assertSame('2027-09-30', $document->expires_on->toDateString());
        $this->assertSame('Contract', $document->kindLabel());

        $tab->assertCanSeeTableRecords([$document]);
    }

    public function test_a_document_warns_once_per_threshold_crossed(): void
    {
        $this->document(['expires_on' => '2026-10-01']);
        $check = app(ContactDocumentExpiry::class);

        $due = $check->due('2026-08-17');
        $this->assertCount(1, $due);
        $this->assertSame(60, $due[0]['threshold']);
        $check->markNotified($due[0]['document'], 60);

        $this->assertCount(0, $check->due('2026-08-18'), 'still inside 60: silent');

        $due = $check->due('2026-09-05');
        $this->assertSame(30, $due[0]['threshold']);
        $check->markNotified($due[0]['document'], 30);

        // Expired falls through to the smallest threshold and stays reported until somebody deals with it.
        $due = $check->due('2026-10-05');
        $this->assertSame(7, $due[0]['threshold']);
        $this->assertSame(-4, $due[0]['days']);
    }

    public function test_renewing_resets_the_warning_by_itself(): void
    {
        $document = $this->document(['expires_on' => '2026-09-01', 'expiry_notified_at_days' => 7]);
        $check = app(ContactDocumentExpiry::class);

        $this->assertCount(0, $check->due('2026-08-28'));

        $document->update(['notes' => 'Renewal under negotiation']);
        $this->assertSame(7, $document->fresh()->expiry_notified_at_days, 'an edit that leaves the expiry alone keeps the marker');

        $document->update(['expires_on' => '2026-10-15']);
        $this->assertNull($document->fresh()->expiry_notified_at_days);
        $this->assertCount(1, $check->due('2026-08-28'));
    }

    public function test_a_document_without_an_expiry_never_warns(): void
    {
        $document = $this->document(['kind' => 'registration', 'title' => 'NTN certificate']);

        $this->assertNull($document->daysUntilExpiry());
        $this->assertFalse($document->hasExpired());
        $this->assertCount(0, app(ContactDocumentExpiry::class)->due('2030-01-01'));
    }

    public function test_the_command_tells_whoever_manages_contacts_once(): void
    {
        Notification::fake();
        $this->document(['expires_on' => '2026-10-01']);
        $this->assertArrayHasKey('contact_document_expiring', EmailTemplate::PLACEHOLDERS);

        $this->artisan('invoicing:check-contact-documents', ['--date' => '2026-08-17', '--tenant' => [$this->tenant->getKey()]])
            ->assertSuccessful();

        Notification::assertSentTo($this->admin, ContactDocumentExpiring::class, fn (ContactDocumentExpiring $sent): bool => $sent->days === 45);
        $this->assertSame(60, ContactDocument::query()->value('expiry_notified_at_days'));

        // The next morning is still inside 60 and says nothing.
        $this->artisan('invoicing:check-contact-documents', ['--date' => '2026-08-18', '--tenant' => [$this->tenant->getKey()]])
            ->assertSuccessful();

        Notification::assertSentToTimes($this->admin, ContactDocumentExpiring::class, 1);
    }

    /** The once-per-threshold rule, now one function shared with Lifecycle. */
    public function test_the_threshold_rule(): void
    {
        $thresholds = [60, 30, 7];

        $this->assertNull(ExpiryThreshold::newlyCrossed(90, $thresholds, null), 'outside every threshold');
        $this->assertSame(60, ExpiryThreshold::newlyCrossed(45, $thresholds, null));
        $this->assertNull(ExpiryThreshold::newlyCrossed(44, $thresholds, 60), 'already warned at 60');
        $this->assertSame(30, ExpiryThreshold::newlyCrossed(20, $thresholds, 60));
        $this->assertSame(7, ExpiryThreshold::newlyCrossed(-3, $thresholds, 30), 'expired falls through to the smallest');
        $this->assertNull(ExpiryThreshold::newlyCrossed(-3, $thresholds, 7));
    }
}
