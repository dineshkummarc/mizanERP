<?php

namespace App\Modules\Core\Models;

use App\Traits\Auditable;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /**
     * Users live in the landlord database. Pin the connection so that when a
     * tenant model (e.g. Employee) eager-loads its `user` relation, the related
     * query does NOT inherit the tenant connection (Eloquent's default) and
     * fail with "no such table: users".
     */
    public function getConnectionName(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: config('database.default');
    }

    /** The tenant-less panel where the installation itself is administered. */
    public const PLATFORM_PANEL = 'platform';

    /**
     * The companies this user may switch into — see getTenants(), which fills it.
     *
     * @var Collection<int, Company>|null
     */
    protected ?Collection $tenants = null;

    /**
     * Determine whether the user can access the given Filament panel.
     *
     * Only active accounts (status = 1) may sign in — mirrors the legacy
     * status-based login rule that previously lived in NovaAuthService.
     *
     * The platform panel additionally requires a super admin, and this is the whole
     * boundary: it has no tenant, so nothing there is scoped to a company, and
     * `Gate::before` grants a super admin every ability. A single missing condition here
     * would hand any active user the installation. Enumerated route by route in
     * PlatformPanelAccessTest rather than sampled.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ((int) $this->status !== 1) {
            return false;
        }

        return $panel->getId() !== self::PLATFORM_PANEL || $this->isSuperAdmin();
    }

    protected static function booted(): void
    {
        // Super-admin is the one installation-level authority; no mass-assignment or crafted form may grant it.
        // Changing it is allowed only by a super admin, or by console/seeding where nobody is signed in —
        // security audit finding 13.
        static::saving(function (self $user): void {
            // Granting super-admin is the escalation that matters; console and seeding have no signed-in actor.
            // On an existing account: promoting it to super-admin is the escalation path (mass assignment or a
            // crafted form). A brand-new record is left to the factory, seeder and platform panel.
            if ($user->exists && $user->is_super_admin && $user->isDirty('is_super_admin')
                && auth()->check() && ! auth()->user()->isSuperAdmin()) {
                throw new \RuntimeException('Only a super administrator may grant super-admin status.');
            }
        });

        /*
         * The last platform admin cannot be deleted or stood down.
         *
         * There is no way back from it: the platform panel admits super admins only, and
         * `is_super_admin` is granted from that panel, so an installation with none has
         * nobody who can appoint one. It would have to be fixed in the database.
         *
         * On the model rather than in a policy, for the reason this codebase has met
         * several times now: `Gate::before` grants a super admin every ability, so a rule
         * that must hold for everybody cannot live where the answer is "yes, you are an
         * administrator". Deactivating counts as standing down — an inactive account
         * cannot sign in, so it is not somebody who can appoint a replacement.
         */
        static::deleting(function (self $user): void {
            if ($user->isTheLastPlatformAdmin()) {
                throw new \InvalidArgumentException(
                    "{$user->name} is the only platform admin left. Appoint another one first — an "
                    .'installation with none has nobody who can grant it, and it would have to be '
                    .'fixed in the database.'
                );
            }
        });

        static::updating(function (self $user): void {
            $standingDown = ($user->isDirty('is_super_admin') && ! $user->is_super_admin)
                || ($user->isDirty('status') && (int) $user->status !== 1);

            if ($standingDown && $user->isTheLastPlatformAdmin()) {
                throw new \InvalidArgumentException(
                    "{$user->name} is the only platform admin left, so this would leave the "
                    .'installation with nobody who can administer it. Appoint another one first.'
                );
            }
        });
    }

    /**
     * The only account that can still open the platform panel.
     *
     * Active, because an inactive one cannot sign in — counting it would let the last two
     * be removed one after the other.
     */
    public function isTheLastPlatformAdmin(): bool
    {
        // The values as they are *stored*, not as they have just been set. Asked from an
        // `updating` hook, the model already carries the change being validated — reading
        // it there would ask "is the account that is no longer a platform admin the last
        // platform admin", which is always no, and the guard would never fire.
        $wasPlatformAdmin = (bool) ($this->exists ? $this->getOriginal('is_super_admin') : $this->is_super_admin);
        $wasActive = (int) ($this->exists ? $this->getOriginal('status') : $this->status) === 1;

        if (! $wasPlatformAdmin || ! $wasActive) {
            return false;
        }

        return ! static::query()
            ->where('is_super_admin', true)
            ->where('status', 1)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Whether this account must have a second factor before it may use the panel.
     *
     * The accounts that can move money: super admins, and anybody holding Administrator in **any**
     * company. Asked team-agnostically on purpose — `hasRole()` reads the *current* permission team, and
     * at the login step there is no company yet, so it would say "no" to every company administrator on
     * the installation. The join below asks the question the way it is meant: does any row anywhere give
     * this person that role.
     *
     * Everyone else may enrol from their profile; nobody is stopped from adding a factor.
     */
    public function mustUseMultiFactorAuthentication(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $registrar = app(PermissionRegistrar::class);

        return \Illuminate\Support\Facades\DB::table(config('permission.table_names.model_has_roles'))
            ->join(
                config('permission.table_names.roles'),
                config('permission.table_names.roles').'.id',
                '=',
                config('permission.table_names.model_has_roles').'.'.$registrar->pivotRole,
            )
            ->where(config('permission.table_names.model_has_roles').'.model_id', $this->getKey())
            ->where(config('permission.table_names.model_has_roles').'.model_type', $this->getMorphClass())
            ->where(config('permission.table_names.roles').'.name', 'Administrator')
            ->exists();
    }

    /** Global super admin — manages companies across all tenants. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Does this user hold administrator authority in the company being served?
     *
     * The same rule Gate::before applies — a super admin may do anything, an
     * Administrator may do anything but create a company — except that this can
     * be asked outside the Gate, which is where it was being got wrong. A
     * canAccess() on a page, an action's visible(): none of them go through the
     * Gate, so `hasRole('Administrator')` on its own quietly excluded super
     * admins. They are not Administrators of most companies — they switch into
     * any company without a membership row, let alone a role in its team — so
     * Company Settings simply vanished from the sidebar of every company they had
     * not been given a role in.
     */
    public function isAdministrator(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole('Administrator');
    }

    /**
     * Users holding a permission — or nobody, when the permission itself is absent.
     *
     * Spatie's `permission()` scope throws `PermissionDoesNotExist` when no such row
     * exists. That is a statement about the environment, not about the caller: a
     * database whose PermissionSeeder has not been re-run since the permission was
     * added. Every caller here is a notification step that runs *after* something has
     * already been saved, so the throw turned a successful save into a 500 and told an
     * employee their expense claim had failed when the row was sitting in the table.
     *
     * Logged rather than swallowed. A claim nobody is waiting on is a real problem, just
     * a smaller one than losing the submission, and the log says what to run.
     */
    public static function holdingPermission(string $permission): Builder
    {
        try {
            return static::permission($permission);
        } catch (PermissionDoesNotExist $e) {
            Log::warning(
                "No permission named {$permission} exists, so nobody could be notified. "
                .'Run `php artisan db:seed --class=PermissionSeeder` — this database is behind the code.'
            );

            return static::whereRaw('1 = 0');
        }
    }

    /**
     * Companies (tenants) this user may access.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withTimestamps();
    }

    /**
     * Take this user off one company: they can no longer sign in to it and hold
     * nothing there.
     *
     * What a company's Users page means by "delete". The account itself is a
     * landlord row shared by every company the person works for, so deleting it
     * from one company's panel would take them out of all the others — and take
     * the row that every payslip, MPR and audit entry of theirs points at. What
     * the page can end is this company's claim on them: the `company_user` pivot,
     * which is what grants access, and their roles here, which are per-company
     * (spatie teams) and would otherwise come back with them on a re-add.
     *
     * Their employee record stays. It is this company's own history — payslips,
     * settings, the lot — and losing it is not what removing a member means.
     */
    public function removeFromCompany(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($company->getKey());
            $this->fresh()?->syncRoles([]);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }

        $this->companies()->detach($company->getKey());
    }

    /**
     * Only the users who may access the company being served.
     *
     * The membership boundary that has to be drawn by hand for this table: it is
     * in the landlord database, shared by every company, so unlike an Employee or
     * a Payslip a User is not isolated by the connection it is read over. Applied
     * for real by UserResource; also available to the queries that go around a
     * resource — a table filter's option list, a select — since those would
     * otherwise name every account in the system.
     *
     * No current company (login, console, a landlord-level job) means no
     * narrowing: there is no company whose members to narrow to.
     *
     * @param  Builder<User>  $query
     */
    public function scopeInCurrentCompany(Builder $query): void
    {
        $company = Filament::getTenant() ?? Company::current();

        if (! $company instanceof Company) {
            return;
        }

        // Both sides are landlord tables, so this subquery does not span the
        // landlord/tenant connection split (see App\Support\LandlordUserColumn
        // for the queries that would).
        $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($company->getKey()));
    }

    /**
     * Drop the platform's own accounts, unless a platform account is asking.
     *
     * A super admin is attached to the companies they create — the provisioner
     * does it, and they hold the Administrator role there — but they are not one
     * of the company's people, and a company has no business administering them.
     * Listed, they came with a Deactivate button and an Edit form: a company
     * administrator could lock the account that runs the installation out of
     * every company in it.
     *
     * @param  Builder<User>  $query
     */
    public function scopeExceptPlatformAdmins(Builder $query): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            return;
        }

        $query->where($query->getModel()->qualifyColumn('is_super_admin'), false);
    }

    /**
     * Every user, current company or not.
     *
     * `users` is a landlord table shared by all companies, so UserResource turns
     * Filament's row scoping back on and, while the panel is serving a request,
     * that lands as a global scope on this model. The Companies resource — super
     * admin only — is the one place that has to reach past it: the whole point of
     * assigning a company's Administrator is picking someone who is not a member
     * yet. Anywhere else, wanting this is a bug.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAcrossCompanies(Builder $query): void
    {
        // No current panel means no scope was ever applied — Filament registers
        // it against one panel and the closure no-ops outside it.
        $panel = Filament::getCurrentPanel();

        if ($panel?->hasTenancy()) {
            $query->withoutGlobalScope($panel->getTenancyScopeName());
        }
    }

    public function getTenants(Panel $panel): Collection
    {
        // Memoised for the length of the request, on the instance that is the
        // authenticated user for exactly that long. Filament asks more than once
        // per page — the tenant menu, the switcher, the tenancy checks — and for
        // a super admin the answer is `Company::all()`, which showed up four
        // times in the statements behind a single page load. Keyed on nothing
        // because the answer depends on nothing: `$panel` is not consulted below.
        return $this->tenants ??= $this->isSuperAdmin() ? Company::all() : $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        // A suspended company admits nobody — status is set from the platform panel and, without this,
        // was stored and never read (security audit 2026-10-26, finding 12). A super admin still reaches it.
        if ($tenant instanceof \App\Modules\Core\Models\Company && (int) $tenant->status !== 1 && ! $this->isSuperAdmin()) {
            return false;
        }

        return $this->isSuperAdmin()
            || $this->companies()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Whether this user may create new companies. Super admins only.
     *
     * Registering a company provisions a database, migrates it and seeds its
     * roles — an installation-level act, not something a company administrator
     * does within their own. It used to be enough to hold Administrator in any
     * company you belonged to, which let any customer's admin add companies to
     * the installation. The rest of company management (view, update, delete —
     * see CompanyPolicy) was already super-admin only; this is the last piece
     * lining up with it.
     */
    public function canCreateCompanies(): bool
    {
        return $this->isSuperAdmin();
    }

    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;

    /**
     * Slack alerts (docs/projects-listing-plan.md §11) post to the installation's default
     * channel — SLACK_BOT_USER_DEFAULT_CHANNEL. Only reached when the bot token is set,
     * because Broadcasting::channels() drops 'slack' from every via() without one.
     *
     * ponytail: one channel for the whole installation; per-user or per-company routing
     * means a column here, when somebody asks for it.
     */
    public function routeNotificationForSlack(): ?string
    {
        return config('services.slack.notifications.channel');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('User');
    }

    protected $fillable = [
        'name',
        'status',
        'email',
        'password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'preferences' => 'array',
        ];
    }
}
