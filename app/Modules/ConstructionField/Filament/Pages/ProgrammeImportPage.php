<?php

namespace App\Modules\ConstructionField\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Services\ProgrammeImport;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * Importing a programme — `docs/construction-management-plan.md` §13.
 *
 * **The one choice on this screen is the one that matters: update, or baseline.** A contractor sends a P6 file every
 * month. An update writes planned dates, actuals and progress and *leaves the accepted programme alone*; a baseline
 * import replaces it. §13 keeps the two pairs of dates apart precisely so a monthly re-programme cannot silently retire
 * the entitlement it was caused by, and this radio is where that decision is made out loud rather than by accident.
 *
 * So the default is **update**, the baseline option says what it will overwrite, and the result notification always
 * states which of the two happened.
 *
 * The format is detected from the file's contents rather than its extension — the extension is what a mail client
 * decided to call it and the first bytes are what the tool wrote.
 */
class ProgrammeImportPage extends Page implements HasForms
{
    use BelongsToModule;
    use InteractsWithForms;

    protected string $view = 'filament.pages.construction.programme-import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Import programme';

    protected static ?string $slug = 'construction/programme-import';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['mode' => ProgrammeImport::MODE_UPDATE]);
    }

    public static function canAccess(): bool
    {
        // The import writes the baseline, which is the document a claim is measured against — so it asks for the
        // programme's own update grant rather than the progress one.
        return auth()->user()?->can('ConstructionProgrammeUpdate') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-programme', 'Programme: Help')];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->statePath('data')
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required(),

                FileUpload::make('file')
                    ->label('Programme export')
                    ->acceptedFileTypes(['text/xml', 'application/xml', 'text/plain', 'application/octet-stream'])
                    ->maxSize(51200)
                    ->storeFiles(false)
                    ->required()
                    ->helperText('A Primavera XER, a P6 XML export or an MS Project XML export. A .mpp or .pp is the tool\'s own binary format and has to be exported first.'),

                /*
                 * **The decision this screen exists to make explicit.**
                 *
                 * Update is the default because it is the monthly case and because it is the safe one: it cannot destroy
                 * anything a claim depends on.
                 */
                Radio::make('mode')
                    ->label('What this file is')
                    ->options([
                        ProgrammeImport::MODE_UPDATE => 'A progress update — leave the accepted programme alone',
                        ProgrammeImport::MODE_BASELINE => 'The accepted programme — set the baseline from this file',
                    ])
                    ->descriptions([
                        ProgrammeImport::MODE_UPDATE => 'Writes planned dates, actuals, progress, float and criticality. Entitlement stays measured against the baseline you already have.',
                        ProgrammeImport::MODE_BASELINE => 'Overwrites the baseline dates on every activity in this file. Do it when a revised programme has been accepted, not every month — a baseline that moves with the plan retires the delays that moved it.',
                    ])
                    ->default(ProgrammeImport::MODE_UPDATE)
                    ->required()
                    ->live(),

                TextInput::make('baseline_revision')
                    ->label('Baseline revision')
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => $get('mode') === ProgrammeImport::MODE_BASELINE)
                    ->helperText('Recorded against every activity this file touches, so a later argument can say which accepted programme a date came from.'),
            ]);
    }

    /** Read from the upload and handed to the service, which owns every rule. */
    public function import(): void
    {
        $state = $this->form->getState();

        $upload = $state['file'] ?? null;
        $file = is_array($upload) ? reset($upload) : $upload;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title('Choose a programme file first.')->send();

            return;
        }

        try {
            $summary = app(ProgrammeImport::class)->import(
                Job::query()->findOrFail($state['job_id']),
                $file->get(),
                $state['mode'] ?? ProgrammeImport::MODE_UPDATE,
                $state['baseline_revision'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        /*
         * **The warnings are printed, not counted away.**
         *
         * The failure mode of an importer is not throwing — it is importing four hundred activities out of five hundred
         * and reporting success. So a run with skipped rows is a warning notification that lists them, and it is
         * persistent, because a toast that fades is the same as no message at all.
         */
        Notification::make()
            ->title($summary->describe())
            ->body(new HtmlString(
                $summary->hasWarnings()
                    ? '<div>'.e(implode(' ', array_slice($summary->warnings, 0, 5))).'</div>'
                    : '<div>Read as '.e($summary->source).'.</div>'
            ))
            ->status($summary->hasWarnings() ? 'warning' : 'success')
            ->persistent($summary->hasWarnings())
            ->send();

        $this->form->fill(['mode' => ProgrammeImport::MODE_UPDATE]);
    }
}
