<?php

namespace App\Modules\Core\Filament\Resources\Users\Actions;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CompanyInvitations;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Invite somebody by email — `docs/bexio-gap-plan.md` Phase 2, item 10.
 *
 * Beside Create on the Users page, for the person who is not staff: an accountant, an auditor, a
 * contractor who needs the books and nothing else. Defaults to `Accountant`, which records and cannot
 * approve, post or pay — the role the seeder built for exactly this.
 */
class InviteUserAction
{
    public static function make(): Action
    {
        return Action::make('invite')
            ->label('Invite by email')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (): bool => Filament::getTenant() instanceof Company
                && (auth()->user()?->can('create', User::class) ?? false))
            ->modalHeading(fn (): string => 'Invite somebody to '.(Filament::getTenant()?->name ?? 'this company'))
            ->modalDescription('For somebody outside the company — an accountant, an auditor. A new account is emailed a '
                .'link to set its password; an existing account is simply given access here. No employee record is made.')
            ->modalSubmitActionLabel('Send the invitation')
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                Select::make('roles')
                    ->label('Roles here')
                    ->multiple()
                    ->required()
                    ->options(fn (): array => Filament::getTenant()?->roles()->orderBy('name')->pluck('name', 'name')->all() ?? [])
                    ->default(['Accountant'])
                    ->helperText('Accountant records and cannot approve, post or pay. Administrator can do anything within this company.'),
            ])
            ->action(function (array $data): void {
                $company = Filament::getTenant();

                if (! $company instanceof Company) {
                    return;
                }

                try {
                    $result = app(CompanyInvitations::class)->invite(
                        $company,
                        $data['email'],
                        $data['name'],
                        $data['roles'] ?? [],
                        auth()->user(),
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($result['created']
                        ? "Invitation sent to {$data['email']}."
                        : "{$result['user']->name} already had an account — access to {$company->name} added and they have been told.")
                    ->send();
            });
    }
}
