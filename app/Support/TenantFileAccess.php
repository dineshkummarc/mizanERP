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
