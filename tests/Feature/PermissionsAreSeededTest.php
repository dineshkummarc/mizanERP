<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Every permission name referenced in app/ must exist after PermissionSeeder runs.
 *
 * The failure this guards against is not a denied button: `hasPermissionTo()` *throws*
 * for a name the database has not got, so a permission checked in code and missing from
 * a module's `module.php` declaration is a 500 on every page that evaluates it. That is
 * exactly how the performance plan's Phase 0 was blocked — a seeder 96 permissions
 * behind whose only symptom was `There is no permission named 'HolidayView'`
 * (docs/page-load-performance-plan.md, "Still outstanding").
 *
 * The scan is deliberately narrow: string literals in the codebase's one convention —
 * a PascalCase name passed to a permission-checking method. Policy abilities and gate
 * names are lowercase-first (`view`, `setGlobal`, `viewHorizon`), so requiring a
 * leading capital keeps them out. A reference this regex cannot see (a name built at
 * runtime) is not asserted; better a reliable subset than false positives.
 */
class PermissionsAreSeededTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_referenced_in_app_is_seeded(): void
    {
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::query()->pluck('name')->flip();

        $referenced = $this->referencedPermissionNames();

        // ~300 names as of writing. The scan finding almost nothing means the regex no
        // longer matches how this codebase checks permissions, not that all is well.
        $this->assertGreaterThan(
            100,
            count($referenced),
            'the static scan found almost no permission references — its pattern has drifted from the codebase',
        );

        $missing = collect($referenced)
            ->reject(fn (array $files, string $name): bool => $seeded->has($name))
            ->map(fn (array $files, string $name): string => "{$name}  (".implode(', ', $files).')')
            ->values()
            ->all();

        $this->assertSame(
            [],
            $missing,
            'These permissions are checked in app/ but not declared in any module.php, so the check throws '
            ."and the page 500s. Add each to its module's 'permissions' array:\n\n".implode("\n", $missing),
        );
    }

    /**
     * PascalCase permission names passed as string literals to a permission check.
     *
     * Covers the forms in use: `$user->hasPermissionTo('EmployeeView')`,
     * `auth()->user()?->can('ReportView')`, `hasDirectPermission(...)`, plus the
     * Gate facade's string forms should they appear.
     *
     * @return array<string, array<int, string>> permission name => files referencing it
     */
    private function referencedPermissionNames(): array
    {
        $pattern = "/(?:->|::)\s*(?:hasPermissionTo|hasDirectPermission|can|allows|denies|authorize)\(\s*['\"]([A-Z][A-Za-z]+)['\"]/";

        $names = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (! preg_match_all($pattern, $file->getContents(), $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $name) {
                $names[$name][] = $file->getRelativePathname();
            }
        }

        ksort($names);

        return $names;
    }
}
