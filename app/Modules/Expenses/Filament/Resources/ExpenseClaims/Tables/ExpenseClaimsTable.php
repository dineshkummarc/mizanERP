<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims\Tables;

use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Services\ExpenseClaimBankPayment;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class ExpenseClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claimed_on')->label('Spent')->date('d M Y')->sortable(),

                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('description')->searchable()->wrap(),

                TextColumn::make('transactionType.name')->label('Category')->placeholder('—')->toggleable(),

                TextColumn::make('amount')->money('PKR')->sortable()->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ExpenseClaim::STATUS_PENDING => 'warning',
                        ExpenseClaim::STATUS_APPROVED => 'info',
                        ExpenseClaim::STATUS_SETTLED => 'success',
                        default => 'danger',
                    })
                    // A refusal without its reason is the complaint the approval step
                    // exists to answer, so the reason travels with the badge.
                    ->description(fn (ExpenseClaim $record): ?string => $record->refusal_reason)
                    ->sortable(),

                TextColumn::make('payslip.month')
                    ->label('Reimbursed on')
                    // A claim paid through the bank has no payslip; the payment it went out on stands in.
                    ->state(fn (ExpenseClaim $record): ?string => $record->payslip?->month
                        ?? ($record->payment_id ? 'Bank transfer' : null))
                    ->placeholder('—')
                    ->description(fn (ExpenseClaim $record): ?string => match (true) {
                        $record->payslip !== null => 'payslip #'.$record->payslip_id,
                        $record->payment_id !== null => 'payment #'.$record->payment_id.($record->isSettled() ? '' : ', not yet released'),
                        default => null,
                    })
                    ->toggleable(),
            ])
            ->defaultSort('claimed_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    ExpenseClaim::STATUS_PENDING => 'Pending',
                    ExpenseClaim::STATUS_APPROVED => 'Approved, not yet paid',
                    ExpenseClaim::STATUS_SETTLED => 'Reimbursed',
                    ExpenseClaim::STATUS_REFUSED => 'Refused',
                ]),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('Receipt')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (ExpenseClaim $record): bool => (bool) $record->receipt_path)
                    ->url(fn (ExpenseClaim $record): string => Storage::disk('public')->url($record->receipt_path), shouldOpenInNewTab: true),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ExpenseClaim $record): string => 'Approving commits to reimbursing '
                        .number_format((float) $record->amount, 2).' with this employee\'s next payslip, and books it '
                        .'now: the expense in its category, and what is owed as a liability until payday.')
                    ->visible(fn (ExpenseClaim $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->action(function (ExpenseClaim $record): void {
                        try {
                            $record->approve(auth()->user());
                        } catch (\InvalidArgumentException|\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved and booked — it will be reimbursed with the next payslip.')->send();
                    }),

                Action::make('refuse')
                    ->label('Refuse')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ExpenseClaim $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->rows(3)
                            ->helperText('Sent to the person who claimed. Being told no without being told why is what this step exists to avoid.'),
                    ])
                    ->action(function (ExpenseClaim $record, array $data): void {
                        try {
                            $record->refuse(auth()->user(), $data['reason']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Refused, with your reason sent to the claimant.')->send();
                    }),

                /**
                 * Pay an approved claim by bank transfer rather than on the payslip — docs/bexio-gap-plan.md
                 * §3.6. Raises a draft payment for the next bank file to the employee's bank details; the claim
                 * leaves the payroll pool at once and is settled when the payment is approved or released.
                 */
                Action::make('payThroughBank')
                    ->label('Pay through the bank')
                    ->icon('heroicon-o-building-library')
                    ->color('gray')
                    ->visible(fn (ExpenseClaim $record): bool => $record->isApproved()
                        && $record->payment_id === null
                        && modules()->enabled('accounting')
                        && (auth()->user()?->can('decide', $record) ?? false))
                    ->modalHeading(fn (ExpenseClaim $record): string => 'Pay claim #'.$record->getKey().' through the bank')
                    ->modalDescription(fn (ExpenseClaim $record): string => number_format((float) $record->amount, 2)
                        .' goes into the next bank file, to the bank details on the employee\'s record, instead of onto '
                        .'their payslip. The claim is settled when the payment is approved or released.')
                    ->modalSubmitActionLabel('Raise the payment')
                    ->schema([
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
                    ->action(function (array $data, ExpenseClaim $record): void {
                        try {
                            $payment = app(ExpenseClaimBankPayment::class)->raise(
                                $record,
                                (int) $data['company_bank_account_id'],
                                $data['value_date'],
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Payment #'.$payment->getKey().' raised for the next bank file; payroll will not reimburse this claim.')
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
