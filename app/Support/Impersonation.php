<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use BadMethodCallException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Signing in as another user, so an administrator can complete work on behalf of
 * staff who cannot reasonably do it themselves — acknowledging a salary change,
 * filling in a profile.
 *
 * The original user's id is kept in the session, never the impersonated one, so
 * the way back does not depend on anything the impersonated session can alter.
 *
 * Two things this deliberately does not let you do:
 *
 *  - impersonate a super admin, from any starting point. A company Administrator
 *    who could would gain the whole platform, and a super admin gains nothing.
 *  - impersonate outside the company you are working in. Users are shared across
 *    companies, so without that check an Administrator of one company could sign
 *    in as somebody who merely happens to also work for another. A super admin is
 *    exempt: their reach is the installation, not a company (see canReach).
 *
 * Actions taken while impersonating are recorded against the impersonated user,
 * because that is whose data changed — but every audit entry additionally carries
 * `impersonated_by` (see ActivityLog), so an acknowledgement an administrator
 * made is never indistinguishable from one the employee made themselves. That
 * matters most for exactly the case this feature exists for: accepting a salary
 * change is a statement of consent, and the record has to show whose.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Where to put the actor back.
     *
     * Impersonation starts from either panel now — a company's own Users list, or the
     * platform one — and the way back has to land where it began. Sending a platform admin
     * to a company panel after they finished would leave them a click away from the screen
     * they were working on, and sending a company Administrator to /platform would show
     * them a 403.
     */
    public const RETURN_URL_KEY = 'impersonator_return_url';

    /**
     * May this user sign in as that one?
     */
    public function allows(?User $actor, ?User $target): bool
    {
        if (! $actor || ! $target) {
            return false;
        }

        // No nesting: the way back is a single stored id, and a chain would make
        // "who is really doing this" unanswerable.
        if ($this->isActive()) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        // Privilege escalation. Checked before the permission, so it holds even
        // for a super admin.
        if ($target->isSuperAdmin()) {
            return false;
        }

        // A deactivated account cannot sign in (User::canAccessPanel), so
        // impersonating one would produce a session that is bounced straight out.
        if ((int) $target->status !== 1) {
            return false;
        }

        if (! $this->canReach($actor, $target)) {
            return false;
        }

        return $actor->isSuperAdmin() || $actor->hasPermissionTo('UserImpersonate');
    }

    /**
     * Begin impersonating. Returns the user now signed in.
     *
     * @throws RuntimeException when the rules above do not allow it — the caller
     *                          is expected to have checked, so reaching this is a bug
     *                          or a tampered request rather than a user mistake.
     */
    public function start(User $target): User
    {
        $actor = Auth::user();

        if (! $this->allows($actor, $target)) {
            throw new RuntimeException('You may not sign in as this user.');
        }

        activity('Impersonation')
            ->performedOn($target)
            ->withProperties([
                'impersonator_id' => $actor->getKey(),
                'impersonator_email' => $actor->email,
                'target_id' => $target->getKey(),
                'target_email' => $target->email,
                'company_id' => Company::current()?->getKey() ?? Filament::getTenant()?->getKey(),
            ])
            ->log("{$actor->email} started signing in as {$target->email}");

        // Both written before the swap: after Auth::login the session is regenerated and
        // $actor is no longer the authenticated user, so neither could be worked out then.
        $actorId = $actor->getKey();
        $returnUrl = $this->currentPanelUrl();

        Auth::login($target);

        $this->refreshSessionPasswordHash($target);

        session([self::SESSION_KEY => $actorId, self::RETURN_URL_KEY => $returnUrl]);

        return $target;
    }

    /**
     * Return to the original account. Safe to call when not impersonating.
     */
    public function stop(): ?User
    {
        $impersonator = $this->impersonator();

        if (! $impersonator) {
            return null;
        }

        $target = Auth::user();

        activity('Impersonation')
            ->performedOn($target ?? $impersonator)
            ->withProperties([
                'impersonator_id' => $impersonator->getKey(),
                'impersonator_email' => $impersonator->email,
                'target_id' => $target?->getKey(),
                'target_email' => $target?->email,
            ])
            ->log("{$impersonator->email} stopped signing in as ".($target?->email ?? 'unknown'));

        session()->forget(self::SESSION_KEY);

        Auth::login($impersonator);

        $this->refreshSessionPasswordHash($impersonator);

        return $impersonator;
    }

    /**
     * Where the actor should be put back, or null if we never knew.
     *
     * Read before stop() clears it, or after — it survives the swap either way, because it
     * is only forgotten by returnUrlAndForget().
     */
    public function returnUrlAndForget(): ?string
    {
        $url = session(self::RETURN_URL_KEY);

        session()->forget(self::RETURN_URL_KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The panel the actor is standing in, as a URL.
     *
     * Null when there is no panel — a console command or a queued job, where there is
     * nowhere to go back to and the caller decides.
     */
    protected function currentPanelUrl(): ?string
    {
        try {
            return Filament::getCurrentPanel()?->getUrl();
        } catch (\Throwable) {
            // A panel that cannot build its own URL — no tenant resolvable, for instance —
            // must not stop somebody signing in as a user. The fallback is the caller's.
            return null;
        }
    }

    /**
     * Re-stamp the session with the password of whoever is now signed in.
     *
     * Without this the swap survives exactly as long as the request that made it.
     * AuthenticateSession keeps a copy of the signed-in user's password hash in
     * the session and logs out — flushing the session, straight to the login
     * screen — the moment the two disagree. Auth::login() does not touch that
     * copy: the middleware refreshes it after each response instead, and it is
     * not among the panel's *persistent* middleware, so it never runs on the
     * Livewire request the button is clicked in. The stamp stayed the
     * administrator's while the session became the target's, and the redirect
     * that follows was the first request to notice.
     *
     * Mirrors the middleware's own storePasswordHashInSession(), HMAC and legacy
     * fallback included, so the value is in whatever form this Laravel compares.
     */
    protected function refreshSessionPasswordHash(User $user): void
    {
        $hash = $user->getAuthPassword();

        try {
            $hash = Auth::guard()->hashPasswordForCookie($hash);
        } catch (BadMethodCallException) {
            // A guard without the HMAC helper stores the raw hash.
        }

        session()->put('password_hash_'.Auth::getDefaultDriver(), $hash);
    }

    public function isActive(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * The real person behind the current session, or null when nobody is
     * impersonating.
     */
    public function impersonator(): ?User
    {
        $id = session(self::SESSION_KEY);

        return $id ? User::find($id) : null;
    }

    /**
     * Is this target within the actor's reach?
     *
     * Reach follows the context you are standing in, which is the whole point of there
     * being two panels.
     *
     * *In a company* — the company panel — the target must work for that company,
     * super admin or not. A super admin needs no membership row of their own (they switch
     * into any company; `getTenants()` hands them all of them), but they are looking at one
     * company's people and that is who they may act as. Reaching another customer's user
     * from inside this one's URL is the confusion the platform panel exists to remove.
     *
     * *In no company* — the platform panel, which only a super admin can open — reach is
     * the installation, and the one thing that still has to hold is that the target has
     * somewhere to land: a user belonging to no company arrives at a panel with no tenant,
     * and being stuck as somebody else with nothing rendering is the failure this feature
     * must not produce.
     */
    protected function canReach(User $actor, User $target): bool
    {
        // A non-super-admin takes on the target's entire reach while impersonating, so the target must not
        // belong to any company the actor does not — otherwise inviting a foreign account into the actor's
        // company (CompanyInvitations) and impersonating it would cross into that account's other tenants.
        // Checked before the per-company rules below, which only ask about the company being worked in.
        if (! $actor->isSuperAdmin()
            && $target->companies()->pluck('companies.id')
                ->diff($actor->companies()->pluck('companies.id'))->isNotEmpty()) {
            return false;
        }

        $company = Filament::getTenant() ?? Company::current();

        if ($company instanceof Company) {
            return $target->companies()->whereKey($company->getKey())->exists()
                && ($actor->isSuperAdmin()
                    || $actor->companies()->whereKey($company->getKey())->exists());
        }

        if ($actor->isSuperAdmin()) {
            return $target->companies()->exists();
        }

        return $this->shareCompany($actor, $target);
    }

    /**
     * Do these two work for at least one company in common?
     *
     * Only asked with no company in context and no super admin — a console command, a
     * queued job, a test that set neither. There is nothing to compare against, so this
     * falls back to a shared company rather than allowing it outright: impersonation is not
     * something to fail open on.
     */
    protected function shareCompany(User $actor, User $target): bool
    {
        return $target->companies()
            ->whereIn('companies.id', $actor->companies()->pluck('companies.id'))
            ->exists();
    }
}
