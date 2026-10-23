<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_define_and_store_a_value(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'vat_no', 'name' => 'VAT No', 'type' => 'text',
        ]);

        $contact = Contact::create(['name' => 'Acme', 'kind' => 'customer', 'is_active' => true]);
        $contact->saveCustomFields(['vat_no' => 'VAT-123']);

        $this->assertSame('VAT-123', $contact->fresh()->customFieldsData()['vat_no']);
    }

    public function test_filament_create_persists_custom_field(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        CustomField::create([
            'model_type' => Contact::class, 'code' => 'region', 'name' => 'Region', 'type' => 'text',
        ]);

        Livewire::test(CreateContact::class)
            ->fillForm([
                'name' => 'Globex',
                'kind' => 'customer',
                'custom_fields' => ['region' => 'North'],
            ])
            ->call('create')
            ->assertHasNoErrors();

        $contact = Contact::where('name', 'Globex')->firstOrFail();
        $this->assertSame('North', $contact->customFieldsData()['region']);
    }

    public function test_per_field_validation_min_length_is_enforced(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        CustomField::create([
            'model_type' => Contact::class, 'code' => 'ntn_code', 'name' => 'NTN', 'type' => 'text', 'min' => 5,
        ]);

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Initech', 'kind' => 'customer', 'custom_fields' => ['ntn_code' => 'ab']])
            ->call('create')
            ->assertHasFormErrors(['custom_fields.ntn_code']);
    }

    public function test_infolist_entries_build_for_a_model(): void
    {
        CustomField::create(['model_type' => Contact::class, 'code' => 'tier', 'name' => 'Tier', 'type' => 'text']);

        $entries = \App\Filament\Support\CustomFieldsSchema::infolistEntries(Contact::class);
        $this->assertCount(1, $entries);
    }

    public function test_env_flag_disables_custom_fields(): void
    {
        config()->set('custom_fields.enabled', false);

        CustomField::create(['model_type' => Contact::class, 'code' => ' off_field', 'name' => 'Off', 'type' => 'text']);

        $this->assertSame([], \App\Filament\Support\CustomFieldsSchema::form(Contact::class));
        $this->assertSame([], \App\Filament\Support\CustomFieldsSchema::tableColumns(Contact::class));
        $this->assertSame([], \App\Filament\Support\CustomFieldsSchema::infolistEntries(Contact::class));
    }
}
