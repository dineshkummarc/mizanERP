<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class InvoiceForm
{
    /**
     * Set the due date from the contact's payment terms.
     *
     * @param  bool  $onlyIfEmpty  True when this fires as a side effect of another
     *                             field changing, so a date somebody typed is left
     *                             alone; false when they pressed the button.
     */
    private static function applyTerms(mixed $contactId, callable $get, callable $set, bool $onlyIfEmpty): void
    {
        if ($onlyIfEmpty && filled($get('due_date'))) {
            return;
        }

        $due = Contact::find($contactId)?->dueDateFor($get('invoice_date') ?: now());

        if ($due !== null) {
            $set('due_date', $due->toDateString());
        }
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: Select 'kind' — onlyOnForms, hideWhenUpdating (disabled on edit).
                Select::make('kind')
                    ->label('Kind')
                    ->options([
                        'sale' => 'Sale (customer invoice)',
                        'purchase' => 'Purchase (supplier bill)',
                    ])
                    ->required()
                    ->disabled(fn (?string $operation): bool => $operation === 'edit')
                    ->dehydrated(),

                Select::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::applyTerms($state, $get, $set, onlyIfEmpty: true))
                    ->helperText(function ($state): ?string {
                        $terms = Contact::find($state)?->paymentTermsLabel();

                        return $terms === null ? null : "Payment terms: {$terms}.";
                    }),

                // GnuCash's "job": which engagement this invoice belongs to.
                // Hidden entirely when Projects is not licensed — the column
                // still exists (every tenant gets every migration) and simply
                // stays empty, which is what keeps Invoicing sellable on its own.
                Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn (): bool => modules()->enabled('projects'))
                    ->helperText('Optional. Lets you ask what one piece of work has been billed, not just what the client owes.'),

                DatePicker::make('invoice_date')
                    ->label('Invoice Date')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::applyTerms($get('contact_id'), $get, $set, onlyIfEmpty: true)),

                // The amounts below are in this currency: it is what the client is
                // billed. The ledger is posted in the company's own currency, at the
                // rate on the invoice date unless one is agreed here.
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => Currency::active()
                        ->orderBy('code')
                        ->pluck('name', 'code')
                        ->map(fn (string $name, string $code): string => "{$code} — {$name}")
                        ->all())
                    ->default(fn (): string => Currency::baseCode())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Invoice $record): bool => $record !== null && ! $record->isDraft())
                    ->helperText('What the client is billed in. Fixed once the invoice is issued.'),

                TextInput::make('exchange_rate')
                    ->label('Agreed rate')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get): bool => $get('currency_code')
                        && $get('currency_code') !== Currency::baseCode())
                    ->disabled(fn (?Invoice $record): bool => $record !== null && ! $record->isDraft())
                    ->helperText(fn (callable $get): string => 'Leave blank to use the rate in force on the invoice date. '
                        .Currency::baseCode().' per 1 '.($get('currency_code') ?: '')),

                DatePicker::make('due_date')
                    ->label('Due Date')
                    ->nullable()
                    // Filled from the contact's terms when it is empty, and never
                    // overwritten once somebody has put a date in it: an invoice
                    // with an agreed date on it is the one thing on this form that
                    // may have been negotiated. Re-applying the terms is a button,
                    // so it is a decision rather than a side effect of correcting
                    // a typo in the invoice date.
                    ->suffixAction(
                        Action::make('applyTerms')
                            ->label('Apply terms')
                            ->icon('heroicon-m-arrow-path')
                            ->tooltip(function (callable $get): string {
                                $contact = Contact::find($get('contact_id'));

                                return $contact === null
                                    ? 'Pick a contact first'
                                    : "Set the due date from {$contact->name}'s terms ({$contact->paymentTermsLabel()})";
                            })
                            ->action(fn (callable $get, callable $set) => static::applyTerms(
                                $get('contact_id'),
                                $get,
                                $set,
                                onlyIfEmpty: false,
                            )),
                    )
                    ->helperText(function (callable $get): ?string {
                        $contact = Contact::find($get('contact_id'));

                        if ($contact?->payment_terms_days === null) {
                            return 'No terms agreed with this contact, so nothing is filled in. Aged reports treat an empty due date as due on the invoice date.';
                        }

                        return null;
                    }),

                TextInput::make('subtotal')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                Toggle::make('tax_inclusive')
                    ->label('Line amounts include tax')
                    ->helperText('The same rate is quoted inclusive by one client and exclusive by another, so it is the invoice that says which.'),

                TextInput::make('tax_amount')
                    ->label('Tax')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Computed from the lines\' rates when any line has one, on issue. Typed only for an invoice that carries no rates.'),

                TextInput::make('total')
                    ->label('Total')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                Textarea::make('memo')
                    ->nullable(),

                // The supplier's own PDF on a bill, the signed copy on a sale — docs/bexio-gap-plan.md
                // Phase 2, item 9. Same disk and limits as a petty-cash receipt; changeable after issue,
                // because the supplier's document often arrives after the bill was keyed from it.
                FileUpload::make('attachment_path')
                    ->label('Attachment')
                    ->helperText('The document this invoice was raised from or sent as. Image or PDF, up to 5 MB.')
                    ->disk('public')
                    ->directory('invoice-attachments')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'])
                    ->maxSize(5120)
                    ->openable()
                    ->downloadable()
                    ->columnSpanFull(),

                ...CustomFieldsSchema::form(Invoice::class),
            ]);
    }
}
