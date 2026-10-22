<?php

namespace App\Support;

use Closure;

/**
 * Relation managers one module contributes to another module's resource.
 *
 * The problem this solves is one-directional. `projects` **requires** `employees`, so Projects may name
 * Employees freely — but the Employee screen wanted a Projects tab, and a resource naming a relation
 * manager from a module that already depends on it is a cycle, which composer cannot express at all.
 * The fix is not to hide the reference but to reverse it: Employees offers a slot, Projects fills it, and
 * Employees never learns that Projects exists.
 *
 * **Why not a string class name on the resource.** Because that is the same edge with the evidence
 * removed. `docs/module-packaging-plan.md` records phase 0 finding exactly this — Payroll reaching
 * Advances through the container "with no import, because an import would make the pair a cycle" — and
 * names it for what it is: the cycle being avoided in the lint rather than in the code. A string still
 * needs the class on disk, so composer would still need the edge; all that changes is that nothing can
 * see it any more.
 *
 * Registered from the contributing module's service provider, which is where the licence guard already
 * lives: a module that is not enabled never registers, so the tab is absent rather than empty.
 */
class ResourceContributions
{
    /**
     * Contributions, keyed by the resource they are added to.
     *
     * @var array<class-string, array<int, class-string>>
     */
    private static array $relationManagers = [];

    /**
     * @param  class-string  $resource  the resource being extended
     * @param  class-string  $relationManager  the manager to add to it
     */
    public static function addRelationManager(string $resource, string $relationManager): void
    {
        // Idempotent: providers boot once per process, but a test that re-registers should not end up
        // rendering the same tab twice.
        if (! in_array($relationManager, self::$relationManagers[$resource] ?? [], true)) {
            self::$relationManagers[$resource][] = $relationManager;
        }
    }

    /**
     * @param  class-string  $resource
     * @return array<int, class-string>
     */
    public static function relationManagersFor(string $resource): array
    {
        return self::$relationManagers[$resource] ?? [];
    }

    /**
     * Row actions one module contributes to another module's table — the same inversion for a button.
     *
     * The first is "Record receipt" on a bank statement line (docs/bexio-gap-plan.md Phase 3): the line is
     * Accounting's, the invoice it settles is Invoicing's, and Accounting may not name an invoice. Keyed by
     * action name so a provider booting twice in a test contributes one button, not two.
     *
     * @var array<class-string, array<string, Closure(): mixed>>
     */
    private static array $recordActions = [];

    /**
     * @param  class-string  $resource  the resource whose table gets the action
     * @param  string  $name  the action's name, for idempotency
     * @param  Closure(): mixed  $factory  builds a fresh Action each time the table is configured
     */
    public static function addRecordAction(string $resource, string $name, Closure $factory): void
    {
        self::$recordActions[$resource][$name] = $factory;
    }

    /**
     * @param  class-string  $resource
     * @return array<int, mixed> freshly built actions
     */
    public static function recordActionsFor(string $resource): array
    {
        return array_values(array_map(fn (Closure $factory) => $factory(), self::$recordActions[$resource] ?? []));
    }

    /**
     * Bulk actions, the same way — the first is Payroll's tax certificates on the Employees list
     * (docs/bexio-gap-plan.md Phase 5), where Employees may not name a certificate Payroll renders.
     *
     * @var array<class-string, array<string, Closure(): mixed>>
     */
    private static array $bulkActions = [];

    /** @param  Closure(): mixed  $factory  builds a fresh BulkAction each time the table is configured */
    public static function addBulkAction(string $resource, string $name, Closure $factory): void
    {
        self::$bulkActions[$resource][$name] = $factory;
    }

    /** @return array<int, mixed> */
    public static function bulkActionsFor(string $resource): array
    {
        return array_values(array_map(fn (Closure $factory) => $factory(), self::$bulkActions[$resource] ?? []));
    }

    /** For tests that need to assert what a resource looks like without contributions. */
    public static function flush(): void
    {
        self::$relationManagers = [];
        self::$recordActions = [];
        self::$bulkActions = [];
    }
}
