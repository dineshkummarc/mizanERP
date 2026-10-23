<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Schemas;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Support\Num;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A day, a person, a code and some minutes.
 *
 * **Time is asked for in minutes** and the helper text says the common values, because that is what the column holds
 * and a form that asked for hours would put the rounding §7.1 rejects right back at the entry point.
 *
 * The rate is **not** on this form. It is resolved at approval, as at the day worked, and frozen there — so a form
 * field for it would be either a lie or a second place for the same figure to live. The panel below shows what the
 * ladder currently says instead, which is a preview and labelled as one.
 */
class LabourRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The day')
                    ->columns(2)
                    ->schema([
                        Select::make('worker_id')
                            ->label('Worker')
                            ->options(fn (): array => Worker::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (Worker $worker): array => [$worker->getKey() => $worker->displayName()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        DatePicker::make('worked_on')
                            ->label('Date worked')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->live()
                            // Not "today": a sheet for last Thursday is entered on Monday, and the rate that applies
                            // is Thursday's.
                            ->helperText('The day the work was done. The rate that applies is that day\'s, not today\'s.'),

                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        Select::make('trade_id')
                            ->label('Worked as')
                            ->options(fn (): array => Trade::query()->active()->orderBy('sort')->orderBy('code')->get()
                                ->mapWithKeys(fn (Trade $trade): array => [$trade->getKey() => $trade->displayName()])
                                ->all())
                            ->searchable()
                            ->live()
                            // Not always the worker's own trade: a mason labouring for a day is costed as a labourer.
                            ->helperText('Leave blank for the worker\'s usual trade. Set it when somebody worked as something else for the day.'),

                        Select::make('cost_code_id')
                            ->label('Cost code')
                            ->options(fn (): array => CostCode::query()
                                ->where('is_leaf', true)->where('is_active', true)
                                ->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('Where the cost lands. Headings cannot take cost — they would double-count in every rolled-up total.'),

                        Select::make('wbs_node_id')
                            ->label('WBS element')
                            ->options(fn (callable $get): array => $get('job_id') === null ? [] : WbsNode::query()
                                ->where('job_id', $get('job_id'))
                                ->orderBy('path')->get()
                                ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                                ->all())
                            ->searchable()
                            ->placeholder('Whole job'),
                    ]),

                Section::make('Time')
                    ->description('In minutes, because a rate multiplied by a rounded decimal of hours drifts visibly across a month. 450 is seven and a half hours.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('normal_minutes')
                            ->label('Normal minutes')
                            ->numeric()
                            ->default(480)
                            ->required()
                            ->helperText('480 = 8 hours, 450 = 7½, 540 = 9.'),

                        TextInput::make('overtime_minutes')
                            ->label('Overtime minutes')
                            ->numeric()
                            ->default(0)
                            ->helperText('Paid at the multiplier on the rate that applies.'),
                    ]),

                Section::make('What this will cost')
                    ->description('A preview from today\'s rate ladder. The figures that count are worked out and frozen when the sheet is approved.')
                    ->schema([
                        Placeholder::make('preview')
                            ->label('At today\'s rates')
                            ->content(fn (callable $get): string => static::preview($get)),
                    ])
                    ->visible(fn (?LabourRecord $record): bool => $record === null || $record->isDraft()),

                Section::make('Notes')
                    ->schema([
                        TextInput::make('description')
                            ->maxLength(255)
                            ->helperText('What was done. Appears on the cost entry, which is what somebody reads on the cost report.'),

                        Textarea::make('notes')->rows(2),
                    ]),
            ]);
    }

    /**
     * The preview line, and it is deliberately explicit about being a preview.
     *
     * §18.1's rule about a healthy figure hiding an absence applies here too: if no rate resolves, this says so in
     * words rather than showing a cost of zero — because zero is what an unapproved sheet would then look like it was
     * going to cost, and somebody would approve it.
     */
    private static function preview(callable $get): string
    {
        $workerId = $get('worker_id');
        $normal = (int) ($get('normal_minutes') ?? 0);
        $overtime = (int) ($get('overtime_minutes') ?? 0);

        if ($workerId === null || $normal + $overtime <= 0) {
            return 'Choose a worker and the minutes worked.';
        }

        $worker = Worker::query()->find($workerId);
        $job = $get('job_id') ? Job::query()->find($get('job_id')) : null;
        $trade = $get('trade_id') ? Trade::query()->find($get('trade_id')) : null;

        $rate = app(LabourRateService::class)->resolve(
            job: $job,
            trade: $trade,
            worker: $worker,
            on: $get('worked_on') ? (string) $get('worked_on') : null,
        );

        if ($rate === null) {
            return 'No labour rate applies to this worker on that date, so the sheet cannot be approved yet. '
                .'Set at least a company default under Labour rates.';
        }

        $labour = $rate->costOf($normal, $overtime);
        $burden = $rate->burdenOn($labour);

        return number_format($rate->costRatePerHour, 2).' per hour × '
            .round(($normal + $overtime) / 60, 2).' hours = '.number_format($labour, 2)
            .($burden > 0 ? ', plus burden of '.number_format($burden, 2)
                .' at '.Num::percent($rate->burdenPercent) : ', with no burden set')
            .'. Total '.number_format($labour + $burden, 2).'.';
    }
}
