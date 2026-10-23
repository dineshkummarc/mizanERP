<?php

namespace App\Support;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search and sort tenant records by their owning user's name or email.
 *
 * `users` lives in the landlord database while `employees`, `mprs`, `payslips`
 * and friends live in a per-company one. Filament's default relationship
 * `searchable()` / `sortable()` builds a `whereHas('user', ...)` subquery on the
 * *tenant* connection, which emits an unqualified `users` and dies with
 * "Base table or view not found: ... .users doesn't exist" — including on the
 * paginator's `select count(*)`, so the page fails to load at all.
 *
 * Both helpers below resolve the user ids on the landlord connection first and
 * then constrain the tenant query by foreign key, so no single SQL statement
 * ever spans the two databases.
 */
class LandlordUserColumn
{
    /**
     * Narrow a tenant query to records whose user matches the search term.
     *
     * @param  array<int, string>  $columns  user columns to match against
     */
    public static function search(
        Builder $query,
        string $search,
        array $columns = ['name', 'email'],
        string $foreignKey = 'user_id',
    ): Builder {
        $term = trim($search);

        if ($term === '') {
            return $query;
        }

        $ids = User::query()
            ->where(function (Builder $q) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            })
            ->pluck('id')
            ->all();

        // No match is still a match constraint — an empty `whereIn` correctly
        // yields no rows, rather than silently returning everything.
        return $query->whereIn($foreignKey, $ids);
    }

    /**
     * Employee ids whose code, or whose owning user's name/email, match the term.
     *
     * For tables that reach the user one hop further out — payslips, employee
     * settings, annual taxes all key on `employee_id` — a nested
     * `whereHas('employee.user')` is the same cross-database mistake one level
     * deeper: the emitted SQL still names `users` while running on the tenant
     * connection. Resolving to ids first keeps every statement inside one
     * database.
     *
     * @param  array<int, string>  $columns  user columns to match against
     * @return array<int, int>
     */
    public static function employeeIdsMatching(string $search, array $columns = ['name', 'email']): array
    {
        $term = trim($search);

        if ($term === '') {
            return [];
        }

        $userIds = User::query()
            ->where(function (Builder $q) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            })
            ->pluck('id')
            ->all();

        return Employee::query()
            ->where('employee_id', 'like', "%{$term}%")
            ->when($userIds !== [], fn (Builder $q) => $q->orWhereIn('user_id', $userIds))
            ->pluck('id')
            ->all();
    }

    /**
     * Order a tenant query by a user column.
     *
     * Only the users actually referenced by the rows in play are looked up, so
     * this stays proportional to the page's result set rather than to the whole
     * landlord users table.
     */
    public static function sort(
        Builder $query,
        string $direction,
        string $column = 'name',
        string $foreignKey = 'user_id',
    ): Builder {
        $referenced = $query->clone()
            ->reorder()
            ->distinct()
            ->pluck($foreignKey)
            ->filter()
            ->all();

        if ($referenced === []) {
            return $query;
        }

        $ordered = User::query()
            ->whereIn('id', $referenced)
            ->orderBy($column, $direction === 'desc' ? 'desc' : 'asc')
            ->pluck('id')
            ->all();

        if ($ordered === []) {
            return $query;
        }

        // A CASE ladder rather than MySQL's FIELD(), which SQLite has no
        // equivalent for. Ids come from the database as integers and are cast
        // again here, so nothing user-supplied reaches the raw expression.
        $whens = [];
        foreach ($ordered as $position => $id) {
            $whens[] = 'when '.(int) $id.' then '.$position;
        }

        $qualified = $query->qualifyColumn($foreignKey);

        return $query->orderByRaw(
            'case '.$qualified.' '.implode(' ', $whens).' else '.count($ordered).' end'
        );
    }
}
