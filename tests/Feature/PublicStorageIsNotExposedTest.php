<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `public/storage` must not exist in this application.
 *
 * Uploads are served by TenantFileController, which checks that the caller is a
 * member of the company whose file they are asking for. The `storage:link`
 * symlink bypasses that entirely: with it in place, `storage/app/public` is
 * readable straight off the web root, and since a company's files live at
 * `storage/app/public/tenants/{id}/…`, anyone who knows a path could fetch
 * another company's payslips — which is precisely what TenantStorage's own
 * docblock says the streaming route exists to prevent.
 *
 * This is not hypothetical. It was found as a real directory holding four
 * employees' payslip PDFs, served by the web server with no authentication in
 * front of them, four of which had also been committed to the repository.
 *
 * So `php artisan storage:link` is the wrong command for this application, and
 * this test is here to say so at the moment somebody runs it rather than months
 * later.
 */
class PublicStorageIsNotExposedTest extends TestCase
{
    public function test_there_is_no_public_storage_link(): void
    {
        $path = public_path('storage');

        $this->assertFalse(
            file_exists($path) || is_link($path),
            'public/storage exists, which serves storage/app/public straight off the web root and '
            ."bypasses TenantFileController's membership check — a company's payslips become readable by "
            .'anyone who knows a path. Remove it (rm -rf public/storage); this application serves uploads '
            .'through the streaming route and does not need storage:link.'
        );
    }

    /**
     * And it cannot be created by accident.
     *
     * A test only fails in CI, which is no help at deploy time: `storage:link` is
     * the standard Laravel command and appears in most hosts' default deploy
     * recipes. With no link configured it is a no-op, so the guard holds on a
     * server where nothing runs the suite.
     */
    public function test_no_symlink_is_configured_for_storage_link_to_create(): void
    {
        $this->assertSame(
            [],
            config('filesystems.links'),
            'A configured link means one `php artisan storage:link` re-exposes '
            .'storage/app/public on the web root. Uploads are served through '
            .'TenantFileController instead — see TenantStorage.'
        );
    }

    /** The reason the symlink is unnecessary, asserted rather than assumed. */
    public function test_uploads_are_addressed_through_the_streaming_route(): void
    {
        // By id, so this needs no database: what is being asserted is the shape of
        // the URL the public disk is given, not any company in particular.
        $this->assertStringContainsString(
            \App\Support\TenantStorage::URL_PREFIX,
            \App\Support\TenantStorage::urlRoot(1),
            'the public disk must address files through the access-checked route, not /storage',
        );

        $this->assertStringNotContainsString('/storage', \App\Support\TenantStorage::urlRoot(1));
    }
}
