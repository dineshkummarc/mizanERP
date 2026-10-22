<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditContact extends EditRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * What they owe, under the name — docs/bexio-gap-plan.md Phase 2, item 4. The Statement action
     * has the movements; this is the figure somebody wants before picking up the phone.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $balance = $this->getRecord()->openBalance();

        return match (true) {
            $balance > 0.004 => 'Owes '.number_format($balance, 2),
            $balance < -0.004 => 'In credit '.number_format(-$balance, 2),
            default => null,
        };
    }
}
