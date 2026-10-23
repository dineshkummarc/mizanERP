<?php

namespace App\Modules\Quotations;

use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Quotations\Console\Commands\ExpireQuotations;
use App\Modules\Quotations\Filament\Pages\QuotationConversion;
use App\Modules\Quotations\Filament\RelationManagers\ContactQuotationsRelationManager;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationLine;
use App\Modules\Quotations\Policies\QuotationLinePolicy;
use App\Modules\Quotations\Policies\QuotationPolicy;
use App\Modules\Quotations\Support\QuotationReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class QuotationsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Quotation::class => QuotationPolicy::class,
        QuotationLine::class => QuotationLinePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerReports();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // Laravel only auto-discovers commands in app/Console/Commands, so a command in a
        // module has to be registered here or it disappears from artisan — and from the
        // scheduler, silently.
        $this->commands([ExpireQuotations::class]);

        $this->contributeToContacts();
    }

    /**
     * The customer's quotes, on the customer — `docs/bexio-gap-plan.md` Phase 2, item 4.
     *
     * `quotations.contact_id` existed and no screen read it from this side. Offered from here because
     * this module requires Invoicing and not the other way round: the same shape Projects uses for the
     * Employee screen (App\Support\ResourceContributions).
     */
    private function contributeToContacts(): void
    {
        Contact::resolveRelationUsing(
            'quotations',
            fn (Contact $contact) => $contact->hasMany(Quotation::class),
        );

        ResourceContributions::addRelationManager(ContactResource::class, ContactQuotationsRelationManager::class);
    }

    /**
     * The conversion report — `docs/reports-expansion-plan.md` Phase 3.3.
     *
     * Filed under *Sales & pipeline* with CRM's five: a quotation is the stage after a deal and before an
     * invoice, and somebody reading a pipeline wants both.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without quotations sees no entry rather than a report that
     * fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Sales & pipeline',
            QuotationConversion::class,
            'What was quoted, what was won, what was billed — and which quotes are about to lapse.',
        );

        ReportRenderers::register(
            'QuotationConversion',
            fn (string $asOf): array => app(QuotationReports::class)->conversion($asOf),
        );
    }
}
