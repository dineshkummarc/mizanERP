<?php

namespace App\Modules\Mpr\Filament\Resources\MPRs\Tables;

use App\Modules\Core\Models\User;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Mpr\Services\MprPdfService;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class MPRSTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                // `users` is a landlord table and MPRs are per-tenant, so the
                // relationship search/sort cannot be used — see LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['name'])),

                TextColumn::make('mpr_date')
                    ->label('Date')
                    ->date('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('User Name')
                    ->options(function (): array {
                        $user = auth()->user();

                        // Privileged means privileged within this company: users
                        // are shared across companies (landlord table), so the
                        // membership scope comes first and the downline narrows
                        // it further. See User::scopeInCurrentCompany().
                        $query = User::inCurrentCompany();

                        // Non-privileged users only filter within their downline.
                        if ($user && ! app(EmployeeAccess::class)->isPrivileged($user)) {
                            $query->whereIn('id', app(EmployeeAccess::class)->accessibleUserIds($user)->all());
                        }

                        return $query->pluck('name', 'id')->toArray();
                    })
                    ->searchable(),
            ])
            ->headerActions([
                self::downloadComparisonAction(),
            ])
            ->recordActions([
                EditAction::make(),
                self::downloadSingleAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Parity with Nova DownloadSingleMprPdf ("Download PDF"): reuse pdf_path or generate a single report,
     * then open the resulting file in a new tab.
     */
    protected static function downloadSingleAction(): Action
    {
        return Action::make('downloadSinglePdf')
            ->label('Download PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->requiresConfirmation(false)
            ->action(function (MPR $record) {
                $userName = $record->user->name ?? 'User';
                $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
                $fileName = 'Mpr/'.$cleanName.'_'.time().'.pdf';

                if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                    $fileName = $record->pdf_path;
                } else {
                    $pdfService = new MprPdfService;
                    $result = $pdfService->generateSingleReport($record->toArray());

                    Storage::disk('public')->makeDirectory(dirname($fileName));
                    $result['pdf']->save(Storage::disk('public')->path($fileName));
                    $record->update(['pdf_path' => $fileName]);
                }

                return redirect()->away(Storage::disk('public')->url($fileName));
            });
    }

    /**
     * Parity with Nova DownloadMprPdf ("Generate / Download PDF", standalone): generate a comparison
     * report (recent two MPRs) for the chosen user and open it in a new tab. Non-admins only see themselves.
     */
    protected static function downloadComparisonAction(): Action
    {
        return Action::make('downloadComparisonPdf')
            ->label('Generate / Download PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->schema(function (): array {
                $user = auth()->user();

                if ($user && ! $user->isAdministrator()) {
                    return [
                        Select::make('user_id')
                            ->label('User')
                            ->options([$user->id => $user->name])
                            ->default($user->id)
                            ->helperText('Your profile is automatically selected for this action.'),
                    ];
                }

                return [
                    Select::make('user_id')
                        ->label('Select User')
                        ->options(User::where('status', 1)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->helperText('Generate Recent Two Pdfs of Selected User'),
                ];
            })
            ->action(function (array $data) {
                $userId = $data['user_id'] ?? auth()->id();

                if (! $userId) {
                    Notification::make()->title('Select any User from dropdown!')->danger()->send();

                    return;
                }

                $user = User::find($userId);
                $pdfService = new MprPdfService;
                $result = $pdfService->generateComparisonReport($userId);

                if (! $result || $result['empty']) {
                    Notification::make()->title('This user has no MPR Record in database')->danger()->send();

                    return;
                }

                $userName = $user->name ?? 'User';
                $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
                $customFileName = 'Mpr/'.$cleanName.'_Comparison_'.time().'.pdf';

                Storage::disk('public')->makeDirectory('Mpr');
                $result['pdf']->save(Storage::disk('public')->path($customFileName));

                return redirect()->away(Storage::disk('public')->url($customFileName));
            });
    }
}
