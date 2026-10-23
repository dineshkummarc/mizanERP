<?php

namespace App\Modules\Core\Filament\Resources\Users\Pages;

use App\Events\UserCreated;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = UserResource::class;

    /**
     * Parity with Nova User::afterCreate() — assign the Employee role and
     * auto-create the linked Employee record.
     */
    protected function afterCreate(): void
    {
        $user = $this->record;

        // Roles chosen on the form (for the current company); default to Employee.
        $roles = array_filter((array) ($this->data['roles'] ?? []));
        $user->syncRoles($roles ?: ['Employee']);

        // The employee record goes in the tenant database this panel is serving,
        // so it only belongs to someone who works here. A super admin can create
        // an account for another company from this page (Company Access on the
        // form, and this hook runs after those memberships are synced) — their
        // employee record belongs in that company's database, not this one.
        // Creating it regardless is what leaves an employee row pointing at a
        // user who is a member somewhere else: no name in the list, since the
        // user is not visible here, and a form that will not save.
        if (! $this->userBelongsToCurrentCompany($user)) {
            return;
        }

        // Announced rather than done here.
        //
        // Creating the employee record was Core reaching into the Employees module
        // (docs/module-packaging-plan.md §9). The condition above stays — a super admin may create an
        // account for another company from this page, and that person's employee record belongs in that
        // company's database — because the page is what knows which tenant it is serving.
        UserCreated::dispatch($user);
    }

    private function userBelongsToCurrentCompany(User $user): bool
    {
        $company = Filament::getTenant();

        return $company === null
            || $user->companies()->whereKey($company->getKey())->exists();
    }
}
