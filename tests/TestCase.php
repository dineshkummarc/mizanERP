<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Default permission "team" (company) id for tests. spatie/laravel-permission
     * runs with teams enabled, so every role seed/assignment/check needs a team
     * context. Domain tests operate under this single default company; tenant
     * tests override it via InteractsWithTenant / makeCurrent.
     */
    protected int $defaultTeamId = 1;

    /** Whether this process has prepared its own storage directory yet — see useParallelStoragePath(). */
    private static bool $parallelStorageReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->defaultTeamId);
    }

    /**
     * The application, with a storage directory of its own under `artisan test --parallel`.
     *
     * Decided before the parent builds the application, so the configuration loads against it and every
     * storage path agrees — the ones fixed in config at boot (the disk roots, the backup include list,
     * compiled views, the log) and the ones resolved at run time (storage_path(), TenantStorage, the
     * tenant filesystem task, the real-tenant SQLite files). Re-pointing config after boot was tried
     * first and left the two halves disagreeing.
     */
    public function createApplication()
    {
        $this->useParallelStoragePath();

        return parent::createApplication();
    }

    /**
     * Laravel isolates the database per process, and Storage::fake's disks, but not the real `storage/`
     * tree. A company's files live under storage/app/public/tenants/{id}; every process's in-memory
     * database numbers its first company 1; and the NIC-image tests delete and re-create the same
     * directory — so two processes running them at once trod on each other's files. The application
     * reads LARAVEL_STORAGE_PATH when it resolves its storage directory, which is the hook. Each process
     * also compiles its own views there, so no two processes ever write the same compiled template.
     *
     * Emptied once per process rather than per test, which is what the shared directory got too.
     */
    private function useParallelStoragePath(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? false;

        if (! $token) {
            return;
        }

        $path = Application::inferBasePath()."/storage/framework/testing/parallel/{$token}";

        if (! self::$parallelStorageReady) {
            $files = new Filesystem;
            $files->deleteDirectory($path);

            foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $directory) {
                $files->ensureDirectoryExists("{$path}/{$directory}");
            }

            self::$parallelStorageReady = true;
        }

        $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $path;
    }
}
