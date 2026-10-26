<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * The module registry, assembled from what each module declares about itself.
 *
 * Every module ships an `app/Modules/{Name}/module.php` returning one array: its
 * registry entry, the classes it owns, the permissions it defines, the grants it
 * suggests. This class finds them, merges them and caches the result.
 *
 * It replaces six central files that every new module had to edit —
 * `config/modules.php`, the five hand-written tables in `ModuleMap`, the literal
 * rows in `PermissionSeeder`, the grants in `RoleSeeder` — which is where ten of
 * the thirteen steps in `docs/new-module-checklist.md` came from. A module is now
 * a directory and a file.
 *
 * Two design points worth stating, because both were forks:
 *
 * **A PHP file, not JSON and not a static method on the provider.** The entries
 * in `config/modules.php` carried paragraphs of load-bearing reasoning — why CRM
 * requires nothing, why Leave deliberately omits Payroll — and those paragraphs
 * are the most valuable content in that file. JSON cannot hold a comment, and a
 * static method would mean booting 22 provider classes to answer "what is this
 * module called", on a path `canAccess()` reaches.
 *
 * **The merged result is written back into `config('modules')`.** There are only
 * three readers of that key in the whole application, and none of them changes.
 * See ModuleManifestServiceProvider.
 */
class ModuleManifest
{
    /**
     * Where the merged manifest is cached. A sibling of `packages.php` and
     * `services.php` — already git-ignored, already writable on every deploy that
     * runs `package:discover`.
     */
    public const CACHE = 'cache/mizan-modules.php';

    /** @var array<string, mixed>|null */
    private static ?array $merged = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }

        $cache = base_path('bootstrap/'.self::CACHE);

        if (is_file($cache)) {
            $cached = @include $cache;

            if (is_array($cached) && $cached !== [] && ! self::isStale($cache, $cached)) {
                return self::$merged = $cached;
            }
        }

        $merged = self::build();
        $merged['built_at'] = time();
        $merged['sources'] = array_values(self::manifestPaths());

        self::write($cache, $merged);

        return self::$merged = $merged;
    }

    /**
     * Read every module.php and merge.
     *
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $merged = [
            'registry' => [],
            'models' => [],
            'resources' => [],
            'pages' => [],
            'widgets' => [],
            // Reportable subjects for the report builder — reports-expansion-plan.md Phase 6, item 1.
            'datasets' => [],
            // The dropdowns whose contents this module lets a company write for itself —
            // see App\Support\OptionLists.
            'option_lists' => [],
            'permission_groups' => [],
            'permissions' => [],
            'role_grants' => [],
            'navigation' => [],
            'navigation_items' => [],
        ];

        // Which modules claimed each navigation label for which domain, kept only long enough to guard: a
        // label claimed for two different domains is a real conflict, and the merged map cannot show it.
        $navigationClaims = [];

        foreach (self::manifestPaths() as $key => $path) {
            $manifest = require $path;

            if (! is_array($manifest)) {
                throw new \RuntimeException("{$path} must return an array.");
            }

            $declared = $manifest['key'] ?? $key;

            if ($declared !== $key) {
                throw new \RuntimeException(
                    "{$path} declares key '{$declared}' but sits in a directory deriving '{$key}'. "
                    .'The directory name is what ModuleMap::moduleFor() reads from a namespace, so they must agree.'
                );
            }

            $merged['registry'][$key] = array_filter([
                'label' => $manifest['label'] ?? null,
                'description' => $manifest['description'] ?? null,
                'requires' => $manifest['requires'] ?? [],
                'licensed_by_default' => $manifest['licensed_by_default'] ?? false,
                'locked' => $manifest['locked'] ?? null,
                'plugin' => $manifest['plugin'] ?? null,
            ], fn ($value) => $value !== null);

            foreach (['models', 'resources', 'pages', 'widgets', 'datasets', 'option_lists', 'permission_groups'] as $table) {
                if (($manifest[$table] ?? []) !== []) {
                    $merged[$table][$key] = $manifest[$table];
                }
            }

            array_push($merged['permissions'], ...($manifest['permissions'] ?? []));

            foreach ($manifest['role_grants'] ?? [] as $role => $names) {
                $merged['role_grants'][$role] = array_merge($merged['role_grants'][$role] ?? [], $names);
            }

            // A navigation group label is shared: "Employee" is declared by ten modules, so ten of them claim
            // it for the People domain. Agreement is the normal case and merges silently; disagreement is
            // caught below rather than resolved by whichever manifest was read last.
            foreach ($manifest['navigation'] ?? [] as $label => $domain) {
                $merged['navigation'][$label] = $domain;
                $navigationClaims[$label][$domain][] = $key;
            }

            foreach ($manifest['navigation_items'] ?? [] as $page => $domain) {
                $merged['navigation_items'][$page] = $domain;
            }
        }

        self::guardAgainstCollisions($merged, $navigationClaims);

        return $merged;
    }

    /**
     * Module key => manifest path, for every module that ships one.
     *
     * @return array<string, string>
     */
    public static function manifestPaths(): array
    {
        $paths = [];
        $root = app_path('Modules');

        if (! File::isDirectory($root)) {
            return $paths;
        }

        foreach (File::directories($root) as $directory) {
            $manifest = $directory.'/module.php';

            if (is_file($manifest)) {
                $paths[Str::snake(basename($directory))] = $manifest;
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * The failures that only become possible once the map is not one array.
     *
     * A duplicate morph alias means two modules writing the same token into the
     * same columns, so rows of one resolve to the model of the other — a
     * same-shaped table holding the wrong data, which no error will ever report.
     * A duplicate permission name means grants splitting across two rows.
     *
     * A navigation label claimed for two domains is the one failure here that is *not* about duplication:
     * group labels are shared on purpose, so the merge cannot tell agreement from disagreement and the claims
     * have to be carried in separately. Left unguarded it would resolve to whichever manifest happened to be
     * read last — alphabetical order — and move a whole group of screens to a different domain silently.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, array<string, array<int, string>>>  $navigationClaims  label => domain => modules
     */
    private static function guardAgainstCollisions(array $merged, array $navigationClaims = []): void
    {
        $problems = [];

        foreach ($navigationClaims as $label => $domains) {
            if (count($domains) > 1) {
                $problems[] = sprintf(
                    'navigation label "%s" is claimed for %s',
                    $label,
                    implode(' and ', array_map(
                        fn (string $domain, array $modules): string => $domain.' (by '.implode(', ', $modules).')',
                        array_keys($domains),
                        $domains,
                    )),
                );
            }
        }

        // A domain that does not exist renders nowhere, so the screens under that label become reachable only
        // by URL — the exact failure NavigationDomains' docblock warns about, arriving via a typo.
        $known = NavigationDomains::keys();

        foreach (['navigation' => 'label', 'navigation_items' => 'page'] as $table => $noun) {
            foreach ($merged[$table] as $claim => $domain) {
                if (! in_array($domain, $known, true)) {
                    $problems[] = sprintf(
                        '%s "%s" claims domain "%s", which is not one of: %s',
                        $noun,
                        $claim,
                        $domain,
                        implode(', ', $known),
                    );
                }
            }
        }

        foreach (['models', 'resources', 'pages', 'widgets', 'datasets', 'option_lists'] as $table) {
            $owners = [];

            foreach ($merged[$table] as $module => $entries) {
                foreach (array_keys($entries) as $alias) {
                    $owners[$alias][] = $module;
                }
            }

            foreach ($owners as $alias => $modules) {
                if (count($modules) > 1) {
                    $problems[] = sprintf('alias %s is claimed by %s', $alias, implode(' and ', $modules));
                }
            }
        }

        $names = array_column($merged['permissions'], 'name');
        $repeated = array_unique(array_diff_assoc($names, array_unique($names)));

        foreach ($repeated as $name) {
            $problems[] = "permission {$name} is declared more than once";
        }

        if ($problems !== []) {
            throw new \RuntimeException(implode("\n", ['Module manifests collide:', ...$problems]));
        }
    }

    /**
     * Whether any manifest, or the modules directory itself, has changed since the
     * cache was written.
     *
     * Checked in **every** environment, deliberately. The alternative — trusting a
     * deploy step to clear it — fails silently and in the worst direction: a
     * manifest that changed but is not visible means a module that is installed,
     * on disk, and absent from the registry, which reads as ungated rather than as
     * broken. `docs/module-packaging-plan.md` lists a stale registry among the
     * failures that produce correct-looking output.
     *
     * The cost is one directory listing plus one `stat` per manifest, and no
     * autoloading.
     *
     * **The set of manifests is compared, not the modules directory's mtime**, and
     * that is a correction rather than a refinement: `app/Modules`' mtime changes
     * when a module *directory* appears, which is not when its `module.php` does.
     * Phase 4 hit exactly that — the directory was created, something rebuilt the
     * cache, the manifest landed a minute later, and the module then stayed absent
     * from the registry with a cache that believed itself fresh. Which is the
     * failure this docblock opens by naming, produced by the check meant to prevent
     * it. Comparing the discovered set catches a manifest added to an existing
     * directory, a manifest deleted, and a module renamed — none of which the mtime
     * heuristic sees reliably.
     */
    private static function isStale(string $cache, array $cached): bool
    {
        $built = $cached['built_at'] ?? 0;

        if (($cached['sources'] ?? []) === []) {
            return true;
        }

        $discovered = array_values(self::manifestPaths());
        $recorded = $cached['sources'];
        sort($recorded);

        if ($discovered !== $recorded) {
            return true;
        }

        foreach ($recorded as $path) {
            if (! is_file($path) || filemtime($path) > $built) {
                return true;
            }
        }

        /*
         * And this file itself, because a *code* change can add a table the cache does not have.
         *
         * Found in Phase 6.1 of docs/reports-expansion-plan.md, which added a `datasets` table here and to
         * nine manifests. Editing the manifests marks the cache stale, so it rebuilt — but every rebuild that
         * followed a change to *this* class alone did not, and the symptom was `ModuleMap::datasets()`
         * returning nothing in one process and eleven in another. Which reads as a broken registry rather
         * than a stale file, and the docblock below already says why that direction of failure is the bad
         * one: a table that is missing looks like a module that owns nothing.
         */
        return filemtime(__FILE__) > $built;
    }

    /**
     * Best effort. A registry that cannot be cached is slower; a registry that
     * throws because it cannot write a file takes down every request, which is a
     * far worse trade than the one Laravel's own PackageManifest makes.
     *
     * @param  array<string, mixed>  $merged
     */
    private static function write(string $cache, array $merged): void
    {
        try {
            File::ensureDirectoryExists(dirname($cache));

            // Written beside the cache and renamed into place. Every process that finds the cache stale
            // rebuilds it at the same moment — Octane's workers after a deploy, ParaTest's processes at the
            // start of a run — and a reader that `include`s a half-written file gets a ParseError, not a
            // stale registry. rename() is atomic on one filesystem: a reader sees the old file or the new.
            $temp = tempnam(dirname($cache), 'mizan-modules-');
            File::put($temp, '<?php return '.var_export($merged, true).';'.PHP_EOL);
            @chmod($temp, 0644);

            if (! @rename($temp, $cache)) {
                @unlink($temp);
            }
        } catch (Throwable) {
            // Left uncached deliberately.
        }
    }

    public static function flush(): void
    {
        self::$merged = null;

        $cache = base_path('bootstrap/'.self::CACHE);

        if (is_file($cache)) {
            @unlink($cache);
        }
    }
}
