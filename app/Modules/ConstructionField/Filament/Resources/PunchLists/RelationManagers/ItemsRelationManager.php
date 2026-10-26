<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\RelationManagers;

use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionField\Models\PunchInspection;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Models\PunchList;
use App\Modules\ConstructionField\Services\PunchListService;
use App\Support\TenantDb;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The items on a list — §16.4.
 *
 * **Two things on this screen carry money and everything else supports them.**
 *
 * *Affects practical completion* is the flag §11's AIA holdback reads. It defaults off, because most snags are paint and
 * sealant and a default of on would hold retention against every one of them and make the figure meaningless inside a
 * week.
 *
 * *Visits* is the attempt count, and §16.4 is explicit that a `closed_at` column cannot hold it: "closed after three
 * failed re-inspections is a different fact from closed first time". A trade that never fixes anything at the first
 * visit is site attendances nobody planned and a back-charge argument with evidence behind it.
 *
 * **Closing an item is not a status somebody sets — it is what a passed inspection does.** The form cannot write the
 * status at all; *Inspect* is the only route, and that is the segregation this register needs, structural rather than
 * granted.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    private function list(): PunchList
    {
        /** @var PunchList $list */
        $list = $this->getOwnerRecord();

        return $list;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Textarea::make('description')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('"Snag" against a room number is a line the trade cannot price and the inspector cannot check.'),

                Select::make('trade_id')
                    ->label('Trade')
                    ->options(fn (): array => static::trades())
                    ->searchable()
                    ->visible(fn (): bool => modules()->enabled('construction_costing'))
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        // Snapshotted, as on the diary: a snag list must read correctly if the trade register is
                        // renamed, or if the cost module is later switched off.
                        $set('trade_label', $state ? (static::trades()[$state] ?? null) : null);
                    }),

                TextInput::make('trade_label')
                    ->label('Trade, in words')
                    ->maxLength(255),

                Select::make('location_id')
                    ->label('Where')
                    ->options(fn (): array => static::locations($this->list()->job_id))
                    ->searchable()
                    ->helperText('From the job\'s location tree, so "every open item in this room" stays answerable.'),

                TextInput::make('grid_reference')
                    ->label('Grid reference')
                    ->maxLength(255)
                    ->helperText('Where in the room — how a structural defect is described on a drawing.'),

                Select::make('priority')
                    ->options(PunchItem::PRIORITIES)
                    ->default('medium')
                    ->required(),

                DatePicker::make('due_on')
                    ->label('To be done by')
                    ->native(false),

                Select::make('responsible_contact_id')
                    ->label('Who puts it right')
                    ->relationship('responsible', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => modules()->enabled('invoicing')),

                TextInput::make('responsible_label')
                    ->label('Who puts it right, in words')
                    ->maxLength(255),

                /*
                 * **The flag §11 reads.** Full width and on its own, because it is the one decision on this form that
                 * changes what a certificate releases.
                 */
                Toggle::make('affects_practical_completion')
                    ->label('Stops the employer taking the building over')
                    ->columnSpanFull()
                    ->helperText('Retention is held back against the cost of rectifying these. Leave it off for paint and sealant — a list where everything is ticked holds back a figure nobody believes.'),

                TextInput::make('cost_to_rectify')
                    ->label('Cost to rectify')
                    ->numeric()
                    ->helperText('An estimate, and it stays one: the holdback needs a figure before anybody has done the work.'),

                FileUpload::make('before_photo_path')
                    ->label('Before')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('public')
                    ->directory('construction/punch-photos')
                    ->maxSize(12288),

                FileUpload::make('after_photo_path')
                    ->label('After')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('public')
                    ->directory('construction/punch-photos')
                    ->maxSize(12288)
                    ->helperText('The pair is the point — the before is what was found and the after is the evidence it was put right.'),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    /**
     * The trade register, read out of the table.
     *
     * `construction_trades` belongs to `construction_costing` and this module requires only `construction`, so this asks
     * the query builder for two strings and never names `Trade` — the same treatment the diary's manpower line gets.
     *
     * @return array<int, string>
     */
    private static function trades(): array
    {
        if (! modules()->enabled('construction_costing')) {
            return [];
        }

        return TenantDb::table('construction_trades')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->code} — {$row->name}"])
            ->all();
    }

    /** @return array<int, string> */
    private static function locations(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Location::query()
            ->where('job_id', $jobId)
            ->orderByRaw('LENGTH(path)')
            ->orderBy('sort_order')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->fullName()])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('reference')
            ->columns([
                TextColumn::make('reference')->label('No.')->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(70)
                    ->searchable()
                    ->description(fn (PunchItem $record): ?string => $record->where()),

                TextColumn::make('trade_label')->label('Trade')->placeholder('—')->toggleable(),

                TextColumn::make('responsible_label')
                    ->label('Who')
                    ->getStateUsing(fn (PunchItem $record): string => $record->responsibleName())
                    ->toggleable(),

                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PunchItem::PRIORITIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn (PunchItem $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                TextColumn::make('affects_practical_completion')
                    ->label('Blocks PC')
                    ->badge()
                    ->getStateUsing(fn (PunchItem $record): ?string => $record->affects_practical_completion
                        ? 'yes'
                        : null)
                    ->placeholder('—')
                    ->color('danger')
                    ->tooltip('Retention is held back against the cost of rectifying this.'),

                TextColumn::make('cost_to_rectify')
                    ->label('To rectify')
                    ->numeric(2)
                    ->alignEnd()
                    // Named, because a blocking item with no estimate is why a holdback is "at least" rather than
                    // "exactly" — the sentence the certificate has to print.
                    ->placeholder(fn (PunchItem $record): string => $record->affects_practical_completion
                        ? 'not priced'
                        : '—')
                    ->toggleable(),

                /*
                 * §16.4's fact. Amber past the first visit, because two visits to one snag is an attendance nobody
                 * planned and the evidence behind a back charge.
                 */
                TextColumn::make('visits')
                    ->label('Visits')
                    ->badge()
                    ->getStateUsing(fn (PunchItem $record): string => (string) $record->attemptsMade())
                    ->color(fn (PunchItem $record): string => $record->neededMoreThanOneVisit() ? 'warning' : 'gray')
                    ->tooltip('Closed after three failed re-inspections is a different fact from closed first time.'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PunchItem::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        PunchItem::STATUS_CLOSED => 'success',
                        PunchItem::STATUS_REJECTED => 'gray',
                        PunchItem::STATUS_READY => 'info',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(PunchItem::STATUSES),
                SelectFilter::make('priority')->options(PunchItem::PRIORITIES),

                Filter::make('blocking')
                    ->label('Blocks practical completion')
                    ->query(fn (Builder $query): Builder => $query->blockingCompletion())
                    ->toggle(),

                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn (Builder $query): Builder => $query->overdue())
                    ->toggle(),

                Filter::make('repeat_visits')
                    ->label('Needed more than one visit')
                    // `has()` rather than a HAVING on a `withCount` alias, which SQLite refuses as a non-aggregate.
                    ->query(fn (Builder $query): Builder => $query->has('inspections', '>', 1))
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->list()) ?? false)
                    ->using(fn (array $data): Model => app(PunchListService::class)->addItem($this->list(), $data)),
            ])
            ->recordActions([
                /*
                 * **The only route to a closed item.**
                 *
                 * A pass closes it and dates the closure the day somebody looked; a failure or a partial leaves it open
                 * and the visit is counted. The status is not settable on the form at all — see the class docblock.
                 */
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->modalHeading('Record a re-inspection')
                    ->modalDescription('A pass closes the item. Partly done and failed both leave it open and count the visit — which is the fact a status column cannot hold.')
                    ->schema([
                        Select::make('result')->options(PunchInspection::RESULTS)->required(),
                        DatePicker::make('inspected_on')->label('Looked at on')->native(false)->default(now())->required(),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->visible(fn (PunchItem $record): bool => auth()->user()?->can('inspect', $record) ?? false)
                    ->action(fn (PunchItem $record, array $data) => static::run(
                        fn () => app(PunchListService::class)->inspect($record, $data),
                        'Inspection recorded.',
                        'A pass closes the item; anything else counts the visit and leaves it open.',
                    )),

                Action::make('reject')
                    ->label('Not a defect')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->modalHeading('Agree this is not a defect')
                    ->modalDescription('Kept as a row with the reason rather than deleted — "we agreed this was not a defect on the 14th" is the answer to a question somebody asks again in month nine.')
                    ->schema([
                        Textarea::make('reason')->label('Why not')->rows(2)->required(),
                    ])
                    ->visible(fn (PunchItem $record): bool => auth()->user()?->can('reject', $record) ?? false)
                    ->action(fn (PunchItem $record, array $data) => static::run(
                        fn () => app(PunchListService::class)->reject($record, $data['reason']),
                        'Recorded as not a defect.',
                        'The reason is on the row.',
                    )),

                EditAction::make()
                    ->visible(fn (PunchItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->using(fn (PunchItem $record, array $data): Model => app(PunchListService::class)
                        ->updateItem($record, $data)),

                DeleteAction::make()
                    ->visible(fn (PunchItem $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('Nothing on this list yet')
            ->emptyStateDescription('Items that affect practical completion are what retention is held back against — mark those and leave the paint and sealant unmarked.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
