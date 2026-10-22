<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Invoicing\Models\CustomerCredit;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\BillPaymentService;
use App\Modules\Invoicing\Services\InvoiceDeliveryService;
use App\Modules\Invoicing\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        // Painted before it is filled.
        //
        // Without this the whole page waits on this table's query, its count and
        // its filters before a single pixel arrives; with it the shell and the
        // heading render immediately and the rows follow in a second request.
        // Applied to the long lists rather than to every table — on a table of
        // twenty rows it buys a round trip and nothing else.
        //
        // See docs/page-load-performance-plan.md.
        return $table
            ->deferLoading()
            ->header(view('filament.tables.saved-views-bar'))
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Invoice::KIND_CREDIT_NOTE => 'Credit note',
                        Invoice::KIND_DEBIT_NOTE => 'Debit note',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sale' => 'info',
                        'purchase' => 'warning',
                        // Danger, like a void: both mean money coming back off a sale, and a
                        // credit note in a list of invoices needs to be impossible to skim past.
                        //
                        // A debit note is the same colour for the same reason, on the other side — Phase 5.
                        Invoice::KIND_CREDIT_NOTE, Invoice::KIND_DEBIT_NOTE => 'danger',
                        default => 'gray',
                    })
                    // What it adjusts, so neither note is ever an orphan on screen.
                    ->description(fn (Invoice $record): ?string => $record->isAdjustment()
                        ? 'against '.($record->creditedInvoice?->invoice_number ?? 'the balance')
                        : null)
                    ->sortable(),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable(),

                TextColumn::make('project.name')
                    ->label('Project')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()
                    ->visible(fn (): bool => modules()->enabled('projects')),

                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'issued' => 'info',
                        'partially_paid' => 'info',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                // In the invoice's own currency, not the company's: that is what the
                // client is billed. Labelling a euro total "PKR" would be a lie a reader
                // has no way to catch.
                TextColumn::make('subtotal')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tax_amount')
                    ->label('Tax')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->sortable(),

                // Signed, so a credit note reads as the negative it is. An unsigned column
                // would show a credit note and the invoice it reverses as two identical
                // positive amounts, which is the one place on this screen a reader could
                // conclude the customer owes twice what they do.
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->color(fn (Invoice $record): ?string => $record->isCreditNote() ? 'danger' : null)
                    ->state(fn (Invoice $record): float => $record->signedOutstanding()),

                TextColumn::make('exchange_rate')
                    ->label('Rate')
                    ->state(fn (Invoice $record): ?string => $record->isForeignCurrency()
                        ? number_format($record->rate(), 4)
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('memo')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Journal Entry')
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(Invoice::class),
            ])
            ->groups([
                Group::make('status')->label('Status'),
                Group::make('kind')->label('Kind'),
            ])
            ->filters([
                ...CustomFieldsSchema::tableFilters(Invoice::class),

                // The half of "jobs" that does the work: pick a project and the
                // list becomes everything billed against that engagement. Hidden
                // with the module, so a company without Projects sees no filter
                // for a field it can never fill.
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => modules()->enabled('projects')),

                // The purchase-side questions — docs/bexio-gap-plan.md Phase 3, item 11: which supplier,
                // what falls due when, and what was it booked to. Each works on the sales side too.
                SelectFilter::make('contact_id')
                    ->label('Customer / supplier')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('due')
                    ->label('Due between')
                    ->schema([
                        DatePicker::make('due_from')->label('Due from'),
                        DatePicker::make('due_to')->label('Due to'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['due_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('due_date', '>=', $from))
                        ->when($data['due_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('due_date', '<=', $to))),

                SelectFilter::make('account')
                    ->label('Posting account')
                    ->options(fn (): array => Account::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->getKey() => "{$account->code} {$account->name}"])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $id) => $q
                            ->whereHas('lines', fn (Builder $line) => $line->where('account_id', $id)))),
            ])
            ->recordActions([
                EditAction::make(),
                ...self::invoiceActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::invoiceBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record actions — parity with Nova IssueInvoice / RecordInvoicePayment / VoidInvoice.
     *
     * @return array<Action>
     */
    protected static function invoiceActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                // A budget that argues back, and only argues — docs/erpnext-gap-plan.md §4 item 8. The
                // confirmation says what a supplier's bill would take over budget, and the button still
                // works: the bill is a fact about money owed, and the ledger must not be kept wrong to
                // keep a plan right. Sales carry no warning; nobody budgets a ceiling on income.
                ->modalDescription(fn (Invoice $record): ?string => self::budgetWarning($record))
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceIssue') ?? false) && $record->isDraft())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->issue($record), 'Issued')),

            /**
             * Spread the revenue over the months the lines say the service covers — the gap plan's deferral
             * generator. Offered only when a line carries service dates and the invoice has not been deferred
             * yet, so on most invoices the button is simply absent rather than present and refused.
             */
            Action::make('defer')
                ->label('Defer over service period')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (Invoice $record): string => self::deferralPreview($record))
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceIssue') ?? false)
                    && ! $record->isDraft()
                    && $record->status !== Invoice::STATUS_VOID
                    && ! $record->isAdjustment()
                    && $record->lines()->whereNotNull('service_from')->whereNotNull('service_to')->exists()
                    && ! $record->events()->where('event', \App\Modules\Invoicing\Models\InvoiceEvent::DEFERRED)->exists())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->deferOverServicePeriod($record), 'Deferred')),

            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                // Hidden on either note rather than offered and refused: nobody pays one, and the service
                // says so at length if asked.
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoicePay') ?? false)
                    && $record->isOpen()
                    && ! $record->isAdjustment())
                ->schema(self::paymentFields())
                ->action(fn (array $data, Invoice $record) => self::run(fn (InvoiceService $s) => self::settle($s, $record, $data), 'Payment recorded')),

            /**
             * Pay a supplier bill through the bank file — docs/bexio-gap-plan.md Phase 3, item 11.
             *
             * Raises a draft payment the bank file picks up; the bill is settled in the books when that
             * payment is approved or released, through the invoice's own settlement path. "Record Payment"
             * above stays for a bill paid some other way and recorded after the fact.
             */
            Action::make('payThroughBank')
                ->label('Pay through the bank')
                ->icon('heroicon-o-building-library')
                ->color('gray')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoicePay') ?? false)
                    && $record->kind === Invoice::KIND_PURCHASE
                    && $record->isOpen())
                ->modalHeading(fn (Invoice $record): string => "Pay {$record->invoice_number} through the bank")
                ->modalDescription('Raises a payment for the next bank file, to the supplier\'s bank details on their '
                    .'contact record. The bill is settled when the payment is approved or released.')
                ->modalSubmitActionLabel('Raise the payment')
                ->schema([
                    TextInput::make('amount')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->required()
                        ->default(fn (Invoice $record): float => app(BillPaymentService::class)->leftToPay($record))
                        ->helperText('What is left to pay, less any payment already raised and waiting.'),

                    Select::make('company_bank_account_id')
                        ->label('Pay from')
                        ->options(fn (): array => CompanyBankAccount::query()
                            ->where('is_active', true)
                            ->orderByDesc('is_default')
                            ->pluck('title', 'id')
                            ->all())
                        ->default(fn (): ?int => CompanyBankAccount::query()
                            ->where('is_active', true)
                            ->orderByDesc('is_default')
                            ->value('id'))
                        ->required(),

                    DatePicker::make('value_date')
                        ->label('Value date')
                        ->default(now()->toDateString())
                        ->required(),
                ])
                ->action(function (array $data, Invoice $record): void {
                    try {
                        $payment = app(BillPaymentService::class)->raise(
                            $record,
                            (float) $data['amount'],
                            (int) $data['company_bank_account_id'],
                            $data['value_date'],
                        );
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Payment #'.$payment->getKey().' of '.number_format((float) $payment->amount, 2).' raised for the next bank file.')
                        ->success()
                        ->send();
                }),

            /**
             * Settle an invoice from money this customer already paid — docs/erpnext-gap-plan.md §2.2.
             *
             * Offered only when they are actually holding something, so on most invoices the button is
             * absent rather than present and refused. The posting is an ordinary settlement whose account
             * is 2600 rather than the bank; see InvoiceService::applyCredit().
             */
            Action::make('applyCredit')
                ->label('Apply credit on account')
                ->icon('heroicon-o-wallet')
                ->color('gray')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoicePay') ?? false)
                    && $record->isOpen()
                    && ! $record->isAdjustment()
                    && $record->isSale()
                    && CustomerCredit::query()->where('contact_id', $record->contact_id)->available()->exists())
                ->modalDescription(fn (Invoice $record): string => 'This customer is holding '
                    .number_format(self::creditHeldBy($record), 2).' that no invoice has claimed. No money moves: '
                    .'the credit is discharged and the invoice settled.')
                ->modalSubmitActionLabel('Apply')
                ->schema([
                    Select::make('credit_id')
                        ->label('Credit')
                        ->options(fn (Invoice $record): array => CustomerCredit::query()
                            ->where('contact_id', $record->contact_id)
                            ->available()
                            ->orderBy('received_on')
                            ->get()
                            ->mapWithKeys(fn (CustomerCredit $credit): array => [$credit->getKey() => $credit->label()])
                            ->all())
                        ->required()
                        ->live(),
                    DatePicker::make('date')->required()->default(now()->toDateString()),
                    TextInput::make('amount')
                        ->numeric()
                        ->step(0.01)
                        ->required()
                        ->minValue(0.01)
                        // Whichever is smaller: there is no sense offering more credit than is left, or more
                        // than the invoice still owes.
                        ->default(fn (Invoice $record): float => round(min(self::creditHeldBy($record), $record->outstanding()), 2))
                        ->helperText(fn (Invoice $record): string => number_format($record->outstanding(), 2).' outstanding on this invoice.'),
                ])
                ->action(fn (array $data, Invoice $record) => self::run(
                    fn (InvoiceService $s) => $s->applyCredit(
                        CustomerCredit::query()->findOrFail($data['credit_id']),
                        $record,
                        (float) $data['amount'],
                        $data['date'],
                    ),
                    'Credit applied',
                )),

            Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (Invoice $record): bool => auth()->user()?->can('view', $record) ?? false)
                ->url(
                    // The route existed with nothing linking to it. The company is a path segment because
                    // it is outside the panel and resolves its tenant from the URL, like every report.
                    fn (Invoice $record): string => route('invoice.pdf', [
                        'company' => Filament::getTenant()?->slug,
                        'invoice' => $record->getKey(),
                    ]),
                    shouldOpenInNewTab: true,
                ),

            /**
             * Email — the document to the customer, PDF attached — `docs/bexio-gap-plan.md` Phase 1.
             *
             * Gated on `InvoiceIssue`: putting an invoice in front of a customer is the same authority as
             * issuing it. Offered on paid invoices too, because "could you send that again?" is the
             * commonest reason to send one; every send is its own entry on the history.
             */
            Action::make('email')
                ->label('Email')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceIssue') ?? false)
                    && in_array($record->kind, [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE], true)
                    && ! $record->isDraft()
                    && $record->status !== Invoice::STATUS_VOID)
                ->requiresConfirmation()
                ->modalHeading(fn (Invoice $record): string => 'Email '
                    .($record->isCreditNote() ? 'credit note' : 'invoice')." {$record->invoice_number}")
                ->modalDescription(fn (Invoice $record): string => ($email = $record->contact?->correspondenceEmail())
                    ? "The PDF goes to {$email}, worded as Settings → Email templates (invoice issued) says. "
                        .'The send is recorded on the invoice\'s history.'
                    : 'The customer has no email address on record. Add one to the contact first.')
                ->modalSubmitActionLabel('Send')
                ->action(function (Invoice $record): void {
                    try {
                        $email = app(InvoiceDeliveryService::class)->send($record);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("Emailed to {$email}.")->success()->send();
                }),

            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false) && $record->isOpen())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->void($record, auth()->user()), 'Voided')),

            /**
             * Credit — the correction for an invoice that can no longer be voided.
             *
             * Gated on `InvoiceVoid` rather than a permission of its own, which is a decision
             * worth stating. Crediting and voiding are the same authority: both reverse a
             * posted sale, and past the 72-hour FBR window crediting *is* the void — the only
             * form of it left. A separate `InvoiceCredit` would model that as two authorities
             * and then have to be granted to every existing role before the button appeared,
             * so the people who could already reverse a sale would lose the ability to until
             * somebody noticed. If the two ever need separating, it is one permission and one
             * seeder line.
             *
             * Offered on paid invoices too, unlike Void. That is most of the point: an invoice
             * that has been reported and paid is exactly the one nothing else can correct.
             */
            Action::make('credit')
                ->label('Credit')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false)
                    && $record->isSale()
                    && ! $record->isDraft()
                    && $record->status !== Invoice::STATUS_VOID
                    && ! $record->isFullyCredited())
                ->modalHeading('Raise a credit note')
                ->modalDescription('This creates a credit note for the whole invoice as a draft. Nothing '
                    .'posts until you issue it, so you can edit its lines down first if only part of the '
                    .'invoice is being credited.')
                ->modalSubmitActionLabel('Create draft credit note')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->rows(2)
                        ->maxLength(255)
                        ->helperText('The first thing anybody asks about a reversed sale, and the only part '
                            .'of it the figures cannot show. Goods returned, billed twice, priced wrong.'),

                    /**
                     * The rule 22 extension, asked for only when it is actually needed.
                     *
                     * Shown when the 180 days from the supply have passed and this company
                     * reports invoices. Asking every time would train people to ignore it, and
                     * asking a company that is not sales-tax registered would be citing a rule
                     * that does not reach them.
                     */
                    TextInput::make('commissioner_ref')
                        ->label('Commissioner extension reference')
                        ->maxLength(255)
                        ->required()
                        ->visible(fn (?Invoice $record): bool => $record !== null
                            && (bool) setting('fbr.enabled', false)
                            && ! $record->creditNoteWindowOpen())
                        ->helperText(fn (?Invoice $record): string => 'This invoice was supplied on '
                            .$record?->invoice_date->format('d M Y')
                            .', so the '.(int) setting('fbr.credit_note_days', 180)
                            .'-day period in which a credit note adjusts output tax has passed. The '
                            .'Commissioner can extend it once, in writing, on request — enter that '
                            .'reference. Without it the credit note would not be admissible.'),

                    DatePicker::make('commissioner_granted_on')
                        ->label('Granted on')
                        ->native(false)
                        ->visible(fn (?Invoice $record): bool => $record !== null
                            && (bool) setting('fbr.enabled', false)
                            && ! $record->creditNoteWindowOpen()),
                ])
                ->action(fn (array $data, Invoice $record) => self::run(
                    fn (InvoiceService $s) => $s->creditNote($record, $data['reason'], null, [
                        'ref' => $data['commissioner_ref'] ?? null,
                        'granted_on' => $data['commissioner_granted_on'] ?? null,
                    ]),
                    'Credit note drafted'
                )),

            /**
             * Debit — the same correction on the purchase side, `docs/erpnext-gap-plan.md` Phase 5.
             *
             * Gated on `InvoiceVoid` for the reason the Credit action gives: reversing a posted document is
             * one authority, and inventing `InvoicePurchaseAdjust` would hide the button from everybody who
             * can already void until a seeder granted it.
             *
             * **Four fields fewer than Credit.** No Commissioner extension and no window, because rule 22
             * bounds the tax on a supply this company made and reported — a supplier's bill is a document
             * received. See `InvoiceService::debitNote()`.
             *
             * Offered on paid bills too, like Credit: a bill already paid is exactly the one nothing else
             * can correct, and the note then stands as a credit against the supplier's balance.
             */
            Action::make('debit')
                ->label('Debit')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false)
                    && $record->kind === Invoice::KIND_PURCHASE
                    && ! $record->isDraft()
                    && $record->status !== Invoice::STATUS_VOID
                    && ! $record->isFullyCredited())
                ->modalHeading('Raise a debit note')
                ->modalDescription('This creates a debit note for the whole bill as a draft. Nothing posts '
                    .'until you issue it, so you can edit its lines down first if only part of the bill is '
                    .'being reversed.')
                ->modalSubmitActionLabel('Create draft debit note')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->rows(2)
                        ->maxLength(255)
                        ->helperText('What the supplier will be told, and the only part of the claim the '
                            .'figures cannot show. Goods returned, billed twice, quantity overstated — and '
                            .'their own credit note reference once you have it.'),
                ])
                ->action(fn (array $data, Invoice $record) => self::run(
                    fn (InvoiceService $s) => $s->debitNote($record, $data['reason']),
                    'Debit note drafted'
                )),
        ];
    }

    /**
     * Bulk equivalents — run over each selected invoice.
     *
     * @return array<BulkAction>
     */
    protected static function invoiceBulkActions(): array
    {
        return [
            BulkAction::make('issueBulk')
                ->label('Issue')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('InvoiceIssue') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => $s->issue($i), 'Issued')),

            BulkAction::make('recordPaymentBulk')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->can('InvoicePay') ?? false)
                ->schema(self::paymentFields())
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => self::settle($s, $i, $data), 'Payment recorded')),

            BulkAction::make('voidBulk')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('InvoiceVoid') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => $s->void($i, auth()->user()), 'Voided')),
        ];
    }

    /**
     * @return array<Field>
     */
    protected static function paymentFields(): array
    {
        // Sales in the base currency only: a certificate is a PKR document about a PKR liability, and the
        // supplier side is deducted on the payment at approval — see InvoiceService::recordPayment().
        $canWithhold = fn (?Invoice $record): bool => $record === null
            || ($record->isSale() && ! $record->isForeignCurrency());

        return [
            DatePicker::make('date')->required()->default(now()->toDateString()),
            TextInput::make('amount')
                ->label('Amount received')
                ->numeric()->step(0.01)->required()->minValue(0.01)
                ->helperText(fn (?Invoice $record): ?string => $record?->isForeignCurrency()
                    ? 'In '.$record->currencyCode().', which is what the invoice is billed in.'
                    : 'What actually reached the bank. If the customer deducted tax, put that below — the two together settle the invoice.'),

            // The customer's side of §153: a corporate customer pays short and hands over a certificate for
            // the difference. Typed here from the certificate, not computed from a rate table — the
            // certificate is the fact, and the rate the customer applied is their business.
            TextInput::make('withheld')
                ->label('Tax withheld by the customer')
                ->numeric()->step(0.01)->minValue(0)->default(0)
                ->visible($canWithhold)
                ->helperText('From their deduction certificate. Held in 1260 Advance Income Tax and claimed on the company\'s own return.'),

            TextInput::make('certificate')
                ->label('Certificate / CPR reference')
                ->maxLength(60)
                ->visible($canWithhold)
                ->helperText('Printed on the Tax Withheld by Customers report, which is what the return is checked against.'),

            // A bank advice saying what actually landed is a fact, and the rate table is
            // only an estimate of it — so the fact can be typed in.
            TextInput::make('rate')
                ->label('Rate the bank gave')
                ->numeric()
                ->minValue(0)
                ->visible(fn (?Invoice $record): bool => $record?->isForeignCurrency() ?? false)
                ->helperText('Leave blank to use the rate in force on the payment date. The difference from '
                    .'the rate the invoice was raised at is a realised gain or loss.'),
        ];
    }

    /**
     * One receipt, from the modal's fields.
     *
     * The service settles by `amount`, so the money received and the tax withheld are added before the
     * call: 92,000 in the bank plus an 8,000 certificate settles a 100,000 invoice.
     */
    protected static function settle(InvoiceService $service, Invoice $invoice, array $data): Invoice
    {
        $withheld = round((float) ($data['withheld'] ?? 0), 2);

        return $service->recordPayment(
            $invoice,
            round((float) $data['amount'] + $withheld, 2),
            $data['date'],
            isset($data['rate']) && $data['rate'] !== '' ? (float) $data['rate'] : null,
            null,
            null,
            $withheld,
            filled($data['certificate'] ?? null) ? (string) $data['certificate'] : null,
        );
    }

    /** What this invoice's customer is holding that no invoice has claimed. */
    protected static function creditHeldBy(Invoice $invoice): float
    {
        return round((float) CustomerCredit::query()
            ->where('contact_id', $invoice->contact_id)
            ->available()
            ->get()
            ->sum(fn (CustomerCredit $credit): float => $credit->remaining()), 2);
    }

    /**
     * What this bill would take over budget, as the Issue confirmation's text — or null for nothing to say.
     */
    protected static function budgetWarning(Invoice $invoice): ?string
    {
        if ($invoice->kind !== Invoice::KIND_PURCHASE) {
            return null;
        }

        $charges = $invoice->lines()->whereNull('product_id')->whereNotNull('account_id')->get()
            ->map(fn ($line): array => [(int) $line->account_id, $line->netAmount()])
            ->all();

        $warnings = $charges === []
            ? []
            : app(\App\Modules\Accounting\Services\BudgetControl::class)->warningsFor($charges, $invoice->invoice_date->toDateString());

        return $warnings === []
            ? null
            : "Over budget if issued:\n\n".implode("\n", $warnings)."\n\nIssue anyway? The bill is what is owed; the budget is what was planned.";
    }

    /** The lines that would be spread, so the confirmation shows the figures before anything posts. */
    protected static function deferralPreview(Invoice $invoice): string
    {
        $lines = $invoice->lines()->whereNotNull('service_from')->whereNotNull('service_to')->get();

        $rows = $lines->map(fn ($line): string => sprintf(
            '%s — %s over %d month%s from %s',
            $line->description,
            number_format($line->netAmount(), 2),
            $line->serviceMonths() ?? 0,
            ($line->serviceMonths() ?? 0) === 1 ? '' : 's',
            $line->service_from->format('M Y'),
        ));

        return "Each line moves out of this month and is recognised a month at a time:\n\n"
            .$rows->implode("\n")
            ."\n\nOnce per invoice; the schedules appear under Scheduled Transactions.";
    }

    /**
     * Run a single-invoice operation, surfacing service validation errors as notifications.
     */
    protected static function run(callable $op, string $label): void
    {
        try {
            $op(app(InvoiceService::class));
            Notification::make()->title("{$label}: processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $service = app(InvoiceService::class);
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$label}: {$done} invoice(s) processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
