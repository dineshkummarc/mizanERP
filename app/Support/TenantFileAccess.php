<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Closure;

/**
 * Who may stream a company's file, beyond being a member of the company.
 *
 * TenantFileController checked membership and nothing else: there was no per-document policy hook, so a
 * document marked restricted — a tender build-up, an incident investigation naming an injured person —
 * was streamed to anybody in the company holding the URL. The construction plan (§15.2) named that as
 * advisory rather than enforced and asked for a Core change, deliberately, rather than something
 * smuggled in behind a construction migration. This is that change.
 *
 * A module registers a resolver from its provider. Asked in registration order with the user, the company
 * and the path; the first to answer true or false decides, and null means "not mine". A path nobody
 * claims streams on membership alone, as every file always has — so a resolver that is absent, or a
 * module that is off, changes nothing. The same shape as ResourceContributions: Core asks, the module
 * that owns the document answers, and Core names no module.
 */
final class TenantFileAccess
{
    /** @var array<int, Closure(User, Company, string): ?bool> */
    private static array $resolvers = [];

    /** @param  Closure(User, Company, string): ?bool  $resolver */
    public static function register(Closure $resolver): void
    {
        self::$resolvers[] = $resolver;
    }

    /**
     * Allow a file under $prefix only to someone who may `view` the record it belongs to.
     *
     * $lookup is given the path and returns the owning record (or null). It runs in the file's company —
     * the one in the URL, which need not be the current tenant — so a cross-company file resolves against
     * the right database. A path that matches no record falls through to membership, which is right for a
     * swept temp file or a name no row owns.
     *
     * @param  Closure(string): ?\Illuminate\Database\Eloquent\Model  $lookup
     */
    public static function registerRecordPrefix(string $prefix, Closure $lookup): void
    {
        self::register(function (User $user, Company $company, string $path) use ($prefix, $lookup): ?bool {
            if (! str_starts_with($path, $prefix)) {
                return null;
            }

            $run = fn () => $lookup($path);
            $switch = ! Company::current()?->is($company) && filled(config('multitenancy.tenant_database_connection_name'));
            $record = $switch ? $company->execute($run) : $run();

            return $record ? $user->can('view', $record) : null;
        });
    }

    /**
     * Allow every file under $prefix only to a holder of $permission.
     *
     * For paths with no owning row to scope to — a transient import file, a receipt whose model has no
     * policy. The permission is the one that governs the records those files belong to.
     */
    public static function registerPermissionPrefix(string $prefix, string $permission): void
    {
        self::register(fn (User $user, Company $company, string $path): ?bool => str_starts_with($path, $prefix)
            ? ($user->can($permission) ?: false)
            : null);
    }

    public static function allows(User $user, Company $company, string $path): bool
    {
        foreach (self::$resolvers as $resolver) {
            $answer = $resolver($user, $company, $path);

            if ($answer !== null) {
                return $answer;
            }
        }

        return true;
    }

    public static function flush(): void
    {
        self::$resolvers = [];
    }
}
