<?php

namespace App\Filament\Support;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * What the signed-in user may actually do with a given model, in words, for the
 * banner at the top of a help panel.
 *
 * Reads the permission names each policy method checks out of the policy's own
 * source, rather than calling the policy. That is deliberate and the whole
 * design rests on it:
 *
 *  - Calling the policy does not work. Several abilities combine a permission
 *    with the state of one record — JournalEntryPolicy::post() is
 *    `hasPermissionTo('JournalEntryPost') && $entry->canBePosted()` — so asking
 *    the Gate about a blank model tells a Manager they cannot post, which is
 *    the opposite of what is true of their role. A banner describes the role,
 *    not one record.
 *  - Hardcoding the names per screen does not work either. The naming is not
 *    uniform: CurrencyPolicy reuses AccountView/AccountCreate/AccountUpdate,
 *    TaxRate reuses the Invoice permissions, and Role uses viewAnyRole rather
 *    than RoleView. Reading the policy picks all of that up on its own and
 *    cannot drift when a policy is re-pointed.
 *
 * The same source-scanning trick ModuleCoverageTest already relies on to find
 * every permission the application checks, applied to one class at a time.
 *
 * A policy that authorises by role instead of permission (EmailTemplatePolicy
 * is `isAdministrator()`) yields no permission names and therefore no banner
 * rows, which is correct: there is nothing granular to report.
 */
final class HelpAccess
{
    /** @var array<class-string, array<string, array<int, string>>> */
    private static array $abilityCache = [];

    /**
     * Abilities that say nothing a reader needs: `view` duplicates `viewAny`
     * for this purpose, and `before` is the bypass hook rather than an ability.
     *
     * @var array<int, string>
     */
    private const NOT_WORTH_LISTING = ['view', 'before', 'restore', 'forceDelete'];

    /**
     * How an ability reads in a sentence. Anything not named here is turned
     * into words from its own method name.
     *
     * @var array<string, string>
     */
    private const VERBS = [
        'viewAny' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
    ];

    /**
     * @return array{role: string|null, can: array<int, string>, cannot: array<int, array{verb: string, who: array<int, string>}>}
     */
    public static function summarise(?User $user, string $model): array
    {
        $summary = ['role' => null, 'can' => [], 'cannot' => []];

        if ($user === null) {
            return $summary;
        }

        $summary['role'] = self::roleNameFor($user);

        $adminOnly = self::adminOnlyAbilitiesFor($model);

        foreach (self::abilitiesFor($model) as $ability => $permissions) {
            $verb = self::VERBS[$ability] ?? Str::lower(Str::headline($ability));

            // Some policies gate on the Administrator role rather than on a
            // permission — EmployeePolicy and EmailTemplatePolicy both do — so
            // there is no permission name to look up, but there is still
            // something true to tell the reader.
            if ($permissions === []) {
                if (! in_array($ability, $adminOnly, true)) {
                    continue;
                }

                if (self::isAdministrator($user)) {
                    $summary['can'][] = $verb;
                } else {
                    $summary['cannot'][] = ['verb' => $verb, 'who' => ['Administrator']];
                }

                continue;
            }

            $holdsAll = collect($permissions)
                ->every(fn (string $permission) => self::holds($user, $permission));

            if ($holdsAll) {
                $summary['can'][] = $verb;

                continue;
            }

            $summary['cannot'][] = [
                'verb' => $verb,
                'who' => self::rolesHolding($permissions),
            ];
        }

        $summary['cannot'] = self::groupByWho($summary['cannot']);

        return $summary;
    }

    /**
     * Fold the blocked abilities into one line per set of roles.
     *
     * An Accountant is blocked from approve, reject, post and reverse by the
     * same three roles; four near-identical sentences read as noise and bury
     * the one that differs (delete, which is Administrator alone).
     *
     * @param  array<int, array{verb: string, who: array<int, string>}>  $blocked
     * @return array<int, array{verb: string, who: array<int, string>}>
     */
    private static function groupByWho(array $blocked): array
    {
        $grouped = [];

        foreach ($blocked as $item) {
            $key = implode('|', $item['who']);
            $grouped[$key]['who'] = $item['who'];
            $grouped[$key]['verbs'][] = $item['verb'];
        }

        return array_values(array_map(fn (array $group) => [
            'verb' => collect($group['verbs'])->join(', ', ' or '),
            'who' => $group['who'],
        ], $grouped));
    }

    /**
     * Ability => the permission names its policy method checks.
     *
     * @return array<string, array<int, string>>
     */
    public static function abilitiesFor(string $model): array
    {
        if (array_key_exists($model, self::$abilityCache)) {
            return self::$abilityCache[$model];
        }

        $policy = Gate::getPolicyFor($model);

        if ($policy === null) {
            return self::$abilityCache[$model] = [];
        }

        $reflection = new ReflectionClass($policy);
        $source = @file($reflection->getFileName()) ?: [];
        $abilities = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $reflection->getName() || $method->isConstructor()) {
                continue;
            }

            if (in_array($method->getName(), self::NOT_WORTH_LISTING, true)) {
                continue;
            }

            $body = implode('', array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            preg_match_all(
                "/(?:hasPermissionTo|hasDirectPermission|can)\(\s*'([A-Za-z]+)'\s*\)/",
                $body,
                $matches,
            );

            $abilities[$method->getName()] = array_values(array_unique($matches[1]));
        }

        return self::$abilityCache[$model] = $abilities;
    }

    /**
     * Abilities whose policy method authorises by Administrator rather than by
     * a permission — `isAdministrator()` or `hasRole('Administrator')`.
     *
     * Without this, a resource whose policy is entirely role-based (Employees,
     * Email Templates, Custom Fields) produces no banner at all, which reads as
     * "we have nothing to tell you" when the truth is "this screen is
     * Administrator-only".
     *
     * @return array<int, string>
     */
    public static function adminOnlyAbilitiesFor(string $model): array
    {
        $policy = Gate::getPolicyFor($model);

        if ($policy === null) {
            return [];
        }

        $reflection = new ReflectionClass($policy);
        $source = @file($reflection->getFileName()) ?: [];
        $abilities = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $reflection->getName() || $method->isConstructor()) {
                continue;
            }

            if (in_array($method->getName(), self::NOT_WORTH_LISTING, true)) {
                continue;
            }

            $body = implode('', array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            if (preg_match("/isAdministrator\(\)|hasRole\(\s*'Administrator'\s*\)/", $body)) {
                $abilities[] = $method->getName();
            }
        }

        return $abilities;
    }

    /** Matches the Gate::before bypass: a super admin counts as an administrator. */
    private static function isAdministrator(User $user): bool
    {
        try {
            return $user->isAdministrator();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Spatie throws PermissionDoesNotExist rather than denying an unknown name,
     * and a help panel is not somewhere to turn that into a 500.
     */
    private static function holds(User $user, string $permission): bool
    {
        // Same reasoning as HelpAction::holdsAny(): hasPermissionTo() does not
        // go through the Gate, so a super admin holding no role in the company
        // they are currently in would otherwise be told they can do nothing.
        if (self::isAdministrator($user)) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Which of this company's roles hold every one of these permissions, so the
     * banner can say who to go to rather than only that you cannot.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private static function rolesHolding(array $permissions): array
    {
        try {
            return Role::query()
                ->where('company_id', getPermissionsTeamId())
                ->get()
                ->filter(fn (Role $role) => collect($permissions)->every(
                    fn (string $permission) => $role->hasPermissionTo($permission)
                ))
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function roleNameFor(User $user): ?string
    {
        try {
            if ($user->isSuperAdmin()) {
                return 'Super Admin';
            }

            return $user->roles->pluck('name')->sort()->join(' and ') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
