<?php

namespace App\Modules\Construction\Policies;

use App\Modules\Construction\Models\Document;
use App\Modules\Core\Models\User;

/**
 * Who may read, change and publish a document.
 *
 * **Publishing is its own permission**, held by Manager and above rather than by whoever uploads drawings.
 * Published is the state that means "build this" (§15), so it is the same segregation the journal-entry powers
 * already keep: the person who records is not the person who authorises.
 *
 * The state machine's rules are *not* here. `DocumentStateMachine` decides what is allowed; this decides who
 * may ask. Mixing them would mean a permission grant silently skipping an approval gate.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionDocumentView');
    }

    /**
     * A restricted document needs its own permission on top of the ordinary one. Enforced, not advisory:
     * the file behind it streams through TenantFileController only to somebody this answers yes for.
     */
    public function view(User $user, Document $document): bool
    {
        if (! $user->can('ConstructionDocumentView')) {
            return false;
        }

        return ! $document->isRestricted() || $user->can('ConstructionDocumentRestrictedView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionDocumentCreate');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('ConstructionDocumentUpdate');
    }

    /**
     * An archived container is the record that it existed, so it is not deletable.
     *
     * Nor is a published one: somebody is building from it. Deleting is for a container raised in error, and
     * archiving is the act for everything else.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->can('ConstructionDocumentDelete')
            && ! in_array($document->cde_state, [Document::STATE_PUBLISHED, Document::STATE_ARCHIVED], true);
    }

    public function publish(User $user, Document $document): bool
    {
        return $user->can('ConstructionDocumentPublish');
    }
}
