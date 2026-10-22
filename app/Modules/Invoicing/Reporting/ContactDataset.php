<?php

namespace App\Modules\Invoicing\Reporting;

use App\Modules\Invoicing\Models\Contact;
use App\Support\EmployeeOptions;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Contacts — `docs/bexio-gap-plan.md` Phase 2, item 7.
 *
 * The export bexio sells as "export to Excel" is, here, a dataset: the report explorer already filters,
 * groups and writes CSV for anything registered, so the whole feature is this class. No period column —
 * a contact is not an event, and a mandatory date range would hide every customer who did nothing this
 * month, which is the list somebody exporting contacts usually wants.
 */
class ContactDataset extends Dataset
{
    public static function label(): string
    {
        return 'Contacts';
    }

    public static function description(): string
    {
        return 'Customers and suppliers, with their terms, limits and who looks after them.';
    }

    public static function model(): string
    {
        return Contact::class;
    }

    public static function permission(): string
    {
        return 'ContactView';
    }

    public static function periodColumn(): ?string
    {
        return null;
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('name', 'Name', groupable: false),
            DatasetColumn::make('kind', 'Kind'),
            DatasetColumn::make('category', 'Category'),
            DatasetColumn::make('email', 'Email', groupable: false),
            DatasetColumn::make('phone', 'Phone', groupable: false),
            DatasetColumn::make('ntn', 'NTN', groupable: false),
            DatasetColumn::make('address_line_1', 'Address', groupable: false),
            DatasetColumn::make('address_line_2', 'Address 2', groupable: false),
            // A plain column rather than a relation: Invoicing may not name Employees, so the label is
            // resolved the way the picker resolves it.
            DatasetColumn::derived(
                'owner',
                'Account manager',
                fn (Contact $contact): ?string => EmployeeOptions::labelFor($contact->owner_employee_id),
            ),
            DatasetColumn::make('payment_terms_days', 'Terms (days)', DatasetColumn::NUMBER),
            DatasetColumn::make('credit_limit', 'Credit limit', DatasetColumn::MONEY),
            DatasetColumn::make('default_discount_pct', 'Discount %', DatasetColumn::NUMBER),
            DatasetColumn::make('is_active', 'Active', DatasetColumn::BOOLEAN),
            DatasetColumn::make('created_at', 'Added', DatasetColumn::DATE),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::select('kind', 'Kind', 'kind', fn (): array => [
                Contact::KIND_CUSTOMER => 'Customer',
                Contact::KIND_SUPPLIER => 'Supplier',
                Contact::KIND_BOTH => 'Both',
            ]),
            DatasetFilter::select('category', 'Category', 'category', fn (): array => array_combine(
                Contact::categories(),
                Contact::categories(),
            )),
            DatasetFilter::search('find', 'Name, email or NTN contains', ['name', 'email', 'ntn']),
        ];
    }
}
