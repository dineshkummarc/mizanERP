<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\EobiReturn;
use App\Support\Banking\SelectsSalaryMonth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The monthly EOBI return, released as a file — `docs/bexio-gap-plan.md` Phase 5, item 17.
 *
 * `EobiReturn` was written and wired to nothing: no page, no report, no command. This is the page,
 * shaped like the FBR tax file beside it, and it does what that service does — reports what the
 * payslips actually carry, submits nothing, and says in words when a month cannot produce a return.
 */
class EobiReturnFile extends Page
{
    use BelongsToModule;
    use SelectsSalaryMonth;

    protected string $view = 'filament.pages.eobi-return-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'EOBI Return';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fiscal_year_id' => $this->defaultFiscalYear()?->id,
            'month' => $this->defaultMonth(),
        ]);
    }

    protected function monthCountQuery(FiscalYear $fiscalYear): Builder
    {
        return Payslip::where('fiscal_year_id', $fiscalYear->id);
    }

    protected function monthCountNoun(): string
    {
        return 'payslips';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->fiscalYearSelect(),
                $this->monthSelect('Contribution month'),
            ])
            ->statePath('data')
            ->columns(2);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRows(): Collection
    {
        $year = $this->fiscalYear();
        $month = $this->data['month'] ?? null;

        return $year && $month ? app(EobiReturn::class)->rows($month, $year) : collect();
    }

    /** Why there is nothing to send, when there is nothing — in words rather than an empty table. */
    public function getReadiness(): ?string
    {
        $year = $this->fiscalYear();
        $month = $this->data['month'] ?? null;

        return $year && $month ? app(EobiReturn::class)->readiness($month, $year) : 'Choose a fiscal year and a month.';
    }

    /** @return array{employees: int, employee_total: float, employer_total: float, grand_total: float} */
    public function getSummary(): array
    {
        $year = $this->fiscalYear();
        $month = $this->data['month'] ?? null;

        return $year && $month
            ? app(EobiReturn::class)->summary($month, $year)
            : ['employees' => 0, 'employee_total' => 0.0, 'employer_total' => 0.0, 'grand_total' => 0.0];
    }

    /** @return array<int, string> */
    public function getMinimumWageWarnings(): array
    {
        return app(EobiReturn::class)->minimumWageWarnings();
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('eobi-return', 'EOBI Return: Help'),
            Action::make('downloadEobiReturn')
                ->label('Download EOBI return (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getRows()->isNotEmpty())
                ->action(function () {
                    $year = $this->fiscalYear();
                    $month = (string) ($this->data['month'] ?? '');
                    $csv = app(EobiReturn::class)->csv($month, $year);

                    return response()->streamDownload(
                        fn () => print $csv,
                        "EOBI return {$month} {$year->name}.csv",
                        ['Content-Type' => 'text/csv'],
                    );
                }),
        ];
    }
}
