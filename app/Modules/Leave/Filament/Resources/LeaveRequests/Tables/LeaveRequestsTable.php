<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Tables;

use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class LeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('leaveType.label')->label('Type')->sortable(),

                TextColumn::make('from_date')
                    ->label('From')
                    ->date('d M Y')
                    ->description(fn (LeaveRequest $record): ?string => $record->is_half_day
                        ? ($record->half_day_period === LeaveRequest::HALF_SECOND ? 'second half' : 'first half')
                        : null)
                    ->sortable(),

                TextColumn::make('to_date')->label('To')->date('d M Y')->sortable(),

                TextColumn::make('days')
                    ->label('Days')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 1), '0'), '.'))
                    // The range is not the cost: weekends and holidays inside it are
                    // skipped, and with the sandwich rule on they are not. Saying
                    // which happened here is what stops "why is this 4 and not 2"
                    // becoming a support call.
                    ->description(fn (LeaveRequest $record): ?string => $record->sandwich_rule_applied
                        ? 'weekends between counted'
                        : null)
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        LeaveRequest::STATUS_PENDING => 'warning',
                        LeaveRequest::STATUS_APPROVED => 'success',
                        LeaveRequest::STATUS_CANCELLED => 'gray',
                        default => 'danger',
                    })
                    // A refusal without its reason is the complaint the approval step
                    // exists to answer, so the reason travels with the badge.
                    ->description(fn (LeaveRequest $record): ?string => $record->refusal_reason ?? $record->awaitingLabel())
                    ->sortable(),

                TextColumn::make('reason')->wrap()->limit(60)->toggleable(),

                TextColumn::make('decider.name')
                    ->label('Decided by')
                    ->placeholder('—')
                    ->description(fn (LeaveRequest $record): ?string => $record->decided_at?->format('d M Y'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('from_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    LeaveRequest::STATUS_PENDING => 'Awaiting a decision',
                    LeaveRequest::STATUS_APPROVED => 'Approved',
                    LeaveRequest::STATUS_REFUSED => 'Refused',
                    LeaveRequest::STATUS_CANCELLED => 'Withdrawn',
                ]),

                SelectFilter::make('leave_type_id')
                    ->label('Type')
                    ->options(fn (): array => LeaveType::orderBy('sort')->pluck('label', 'id')->all()),

                // Overlap, not containment: leave running from December into January
                // is this year's problem as much as next year's, and a filter that
                // only matched contained ranges would hide it in both.
                Filter::make('current_year')
                    ->label('Touching this year')
                    ->query(fn (Builder $query): Builder => $query->overlapping(
                        now()->startOfYear()->toDateString(),
                        now()->endOfYear()->toDateString(),
                    )),
            ])
            ->recordActions([
                Action::make('document')
                    ->label('Document')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (LeaveRequest $record): bool => (bool) $record->document_path)
                    ->url(fn (LeaveRequest $record): string => Storage::disk('public')->url($record->document_path), shouldOpenInNewTab: true),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (LeaveRequest $record): string => static::approvalDescription($record))
                    ->visible(fn (LeaveRequest $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(LeaveRequestService::class)->approve($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title('Approved — the days it uses are recorded against this leave year.')
                            ->send();
                    }),

                Action::make('refuse')
                    ->label('Refuse')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->rows(3)
                            ->helperText('Sent to the person who asked. Being told no without being told why is what this step exists to avoid.'),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        try {
                            app(LeaveRequestService::class)->refuse($record, auth()->user(), $data['reason']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Refused, with your reason sent to them.')->send();
                    }),

                Action::make('cancel')
                    ->label('Withdraw')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('The days this used go back into the balance. The request stays on the record as withdrawn.')
                    ->visible(fn (LeaveRequest $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(LeaveRequestService::class)->cancel($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Withdrawn, and the days are back in the balance.')->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * What approving actually costs, said before the button is pressed.
     *
     * The balance after approval is the number an approver is deciding on, and being
     * told it afterwards is the wrong order — the same reasoning LoanForm's preview
     * uses. Going over is not blocked (an employee over their balance has usually
     * taken leave the company agreed to), so this is the only place the overrun is
     * visible.
     */
    private static function approvalDescription(LeaveRequest $record): string
    {
        $after = app(LeaveRequestService::class)->balanceAfter($record);

        if ($after === null) {
            return 'This type is not counted against a balance.';
        }

        $format = rtrim(rtrim(number_format($after, 1), '0'), '.');

        return $after < 0
            ? "This leaves {$format} days — over the entitlement. Approving is allowed; the overrun will show on the balance."
            : "This leaves {$format} day(s) of this type for the leave year.";
    }
}
