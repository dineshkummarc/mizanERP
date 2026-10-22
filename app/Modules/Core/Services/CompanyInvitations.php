<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Notifications\CompanyInvitation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Giving an outsider access to a company — `docs/bexio-gap-plan.md` Phase 2, item 10.
 *
 * bexio's "give your fiduciary access for free". The role (`Accountant`, segregated by design) and
 * multi-company membership both existed; what did not was a front door that a company administrator
 * could open without a platform admin creating the account by hand and telling somebody a password.
 *
 * **No employee record.** The Users page's Create announces `UserCreated`, and Employees answers by
 * making an employee row — right for staff, wrong for an auditor. An invitation makes an account and
 * a membership and nothing else; a person who turns out to be staff can be given an employee record
 * from the Employees screen.
 */
class CompanyInvitations
{
    /**
     * @param  array<int, string>  $roles  role names in this company
     * @return array{user: User, created: bool}
     *
     * @throws InvalidArgumentException when a role named does not exist in this company
     */
    public function invite(Company $company, string $email, string $name, array $roles, User $inviter): array
    {
        $roles = array_values(array_filter(array_map('trim', $roles)));

        if ($roles === []) {
            throw new InvalidArgumentException('Choose at least one role: a member with none has a panel with nothing in it.');
        }

        $known = $company->roles()->pluck('name')->all();

        if ($unknown = array_diff($roles, $known)) {
            throw new InvalidArgumentException('This company has no role called '.implode(', ', $unknown).'.');
        }

        $user = User::query()->where('email', $email)->first();
        $created = $user === null;

        if ($created) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                // Unusable on purpose: the invitation carries the link that sets the real one.
                'password' => Hash::make(Str::random(40)),
                'status' => 1,
            ]);
        }

        $user->companies()->syncWithoutDetaching([$company->getKey()]);

        $this->withTeam($company, fn () => $user->syncRoles($roles));

        $user->notify(new CompanyInvitation(
            company: $company->name,
            roles: $roles,
            inviter: $inviter->name,
            url: $created
                ? Filament::getResetPasswordUrl(Password::broker()->createToken($user), $user)
                : Filament::getPanel('admin')->getLoginUrl() ?? url('/'),
            setsPassword: $created,
        ));

        activity('User')
            ->performedOn($user)
            ->causedBy($inviter)
            ->event('invited')
            ->withProperties(['company' => $company->name, 'roles' => $roles, 'created' => $created])
            ->log($created
                ? "{$user->email} invited to {$company->name} as ".implode(', ', $roles)
                : "{$user->email} given access to {$company->name} as ".implode(', ', $roles));

        return ['user' => $user, 'created' => $created];
    }

    /**
     * Roles are per company (spatie teams). Set explicitly rather than trusting whatever the registrar
     * currently points at, and put it back afterwards — MembersRelationManager's reasoning.
     */
    private function withTeam(Company $company, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }
}
