<?php

namespace App\Modules\Employees\Filament\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\CertificateBatch;
use App\Modules\Employees\Services\IncomeCertificate;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Income certificates for every selected employee at once — `docs/bexio-gap-plan.md` Phase 5, item 18.
 * The purpose is typed once and printed on each; everything else comes off the record.
 */
class IncomeCertificatesBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('incomeCertificates')
            ->label('Income certificates')
            ->icon('heroicon-o-identification')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('EmployeeView') ?? false)
            ->modalHeading('Certificates of employment and source of income')
            ->modalDescription('One per selected employee, from the record as it stands. Rendered fresh and never stored; '
                .'anybody missing a fact the letter cannot invent is left out and named.')
            ->modalSubmitActionLabel('Issue')
            ->schema([
                TextInput::make('purpose')
                    ->label('What are they for?')
                    ->required()
                    ->maxLength(120)
                    ->placeholder('Bank account opening'),
                Radio::make('deliver')
                    ->label('Deliver as')
                    ->options(['zip' => 'One zip to download', 'email' => 'An email to each employee, certificate attached'])
                    ->default('zip')
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records) {
                $certificates = app(IncomeCertificate::class);

                $batch = (new CertificateBatch)->run(
                    $records,
                    fn (Employee $employee): array => $certificates->missingFor($employee),
                    fn (Employee $employee) => $certificates->renderPdf($employee, ['purpose' => $data['purpose']]),
                    $data['deliver'],
                    'income certificate',
                );

                Notification::make()
                    ->title('Income certificates: '.$batch->summary($data['deliver']))
                    ->{$batch->skipped === [] ? 'success' : 'warning'}()
                    ->persistent()
                    ->send();

                if ($data['deliver'] === 'zip' && ! $batch->bundle->isEmpty()) {
                    return $batch->bundle->download('income-certificates-'.now()->format('Y-m-d').'.zip');
                }

                return null;
            });
    }
}
