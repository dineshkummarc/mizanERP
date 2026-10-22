<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Core\Models\Bank;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Support\IncomeCertificatesBulkAction;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use App\Support\ResourceContributions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeesTable
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
            ->columns([
                // Nova ID: canSee Administrators only.
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()?->isAdministrator() ?? false),

                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable(),

                // Name and email live on the landlord `users` table while
                // employees live in the tenant database, so these cannot use
                // Filament's relationship search/sort — see LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label('Employee Name')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['name'])),

                TextColumn::make('user.email')
                    ->label('Company Email')
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['email'])),

                TextColumn::make('personal_email')
                    ->label('Personal Email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('secondary_phone')
                    ->label('Secondary Phone')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => (int) $state === 1 ? 'Active' : 'Inactive'),

                TextColumn::make('bank_code')
                    ->searchable(),

                TextColumn::make('bank_short_code')
                    ->searchable(),

                ...CustomFieldsSchema::tableColumns(Employee::class),
            ])
            ->filters([
                ...CustomFieldsSchema::tableFilters(Employee::class),

                SelectFilter::make('employee_name')
                    ->label('Employee Name')
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->name ?? 'Unknown'])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('employee_email')
                    ->label('Company Email')
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->email ?? 'No Email'])
                        ->toArray())
                    ->searchable(),

                /*
                 * Which bank an employee is paid into.
                 *
                 * This replaces the read-only Employees list that used to hang off BankResource as a
                 * relation manager. That list needed `Bank::employees()`, which is the relation that kept
                 * the bank table tied to the Employees module and stopped either being packaged
                 * separately (docs/module-packaging-plan.md §7). The question it answered — "who banks
                 * here" — belongs on this screen anyway: it composes with the other filters, it obeys the
                 * downline scoping every other filter here obeys, and it is where somebody looking for an
                 * employee already is.
                 *
                 * Filters on `bank_id` rather than on the denormalised `bank_code`, because two banks can
                 * share a code in a chart that has been edited by hand and the id cannot.
                 */
                SelectFilter::make('bank_id')
                    ->label('Bank')
                    ->options(fn (): array => Bank::query()
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Certificates for many at once — docs/bexio-gap-plan.md Phase 5, item 18. The income
                    // certificate is this module's; the tax certificate is Payroll's and arrives from there.
                    IncomeCertificatesBulkAction::make(),
                    ...ResourceContributions::bulkActionsFor(EmployeeResource::class),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Employees the current user may filter by (own + downline; all if privileged). */
    protected static function accessibleEmployees(): Collection
    {
        $query = Employee::with('user');

        return app(EmployeeAccess::class)
            ->scopeAccessibleEmployees($query, auth()->user())
            ->get();
    }
}
