<?php

namespace App\Modules\Invoicing\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Services\ContactDocumentExpiry;
use App\Notifications\ContactDocumentExpiring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Warn that a customer's or supplier's document is about to lapse — once per threshold, not once per day.
 *
 * Lifecycle's CheckDocumentExpiry for contact documents (docs/bexio-gap-plan.md §3.7), with the same
 * recipients rule: whoever may edit contacts. The contact's owner would be the better reader, but the
 * owner is an employee and Invoicing may not name Employees; the holders of ContactUpdate are who the
 * screen already trusts with the document.
 */
class CheckContactDocumentExpiry extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'invoicing:check-contact-documents {--tenant=*} {--date= : Treat this date as today}';

    protected $description = 'Warn whoever manages contacts that a contract or document is about to lapse, once per threshold';

    public function handle(ContactDocumentExpiry $check): int
    {
        if ($this->skipsDisabledModule('invoicing')) {
            return self::SUCCESS;
        }

        $due = $check->due($this->option('date'));

        if ($due->isEmpty()) {
            $this->info('No contact documents have newly crossed a warning threshold.');

            return self::SUCCESS;
        }

        $recipients = User::holdingPermission('ContactUpdate')->where('status', 1)->get();

        foreach ($due as $entry) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ContactDocumentExpiring($entry['document'], $entry['days']));
            }

            // Marked whether or not anybody was notified, as Lifecycle does: a company with nobody holding
            // the permission would otherwise re-report the same document every night for ever.
            $check->markNotified($entry['document'], $entry['threshold']);
        }

        $this->info('Warned about: '.$due->map(
            fn (array $entry): string => $entry['document']->title.' for '.($entry['document']->contact?->name ?? 'unknown')
        )->implode(', ').'.');

        return self::SUCCESS;
    }
}
