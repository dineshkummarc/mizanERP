<?php

namespace App\Modules\Invoicing\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Models\ContactDocument;

/**
 * A document on a contact is part of the contact: whoever may read the contact may read it, whoever may
 * edit the contact may file, replace or remove one. No permission group of its own — Lifecycle gave
 * employee documents one because a passport scan is a different sensitivity from an onboarding tick-box,
 * and a customer's contract is the same sensitivity as the credit limit that sits beside it.
 */
class ContactDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ContactView');
    }

    public function view(User $user, ContactDocument $document): bool
    {
        return $user->hasPermissionTo('ContactView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ContactUpdate');
    }

    public function update(User $user, ContactDocument $document): bool
    {
        return $user->hasPermissionTo('ContactUpdate');
    }

    public function delete(User $user, ContactDocument $document): bool
    {
        return $user->hasPermissionTo('ContactUpdate');
    }
}
