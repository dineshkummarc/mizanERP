<?php

namespace App\Support\Pdf;

use Symfony\Component\Process\Process;

/**
 * Detects whether Node can drive Chrome for PDF rendering — a Node binary that answers, with puppeteer
 * installed beside this application — so rendering can fall back to a pure-PHP engine where it cannot.
 *
 * Node alone is not an engine. Browsershot drives Chrome through puppeteer, which `npm ci` installs into
 * node_modules; a CI runner or a fresh server has Node and no puppeteer, and before the second probe
 * "auto" chose an engine that could not render anything. GitHub's ubuntu image was the first to show
 * it (2026-10-23).
 */
class NodeRuntime
{
    protected static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return static::$available ??= static::probe();
    }

    /** Reset the memoised result (tests). */
    public static function flush(): void
    {
        static::$available = null;
    }

    protected static function probe(): bool
    {
        $binary = (string) config('services.node.binary', 'node');

        if ($binary === '') {
            return false;
        }

        // An absolute path we were handed but which is not there is a clear no.
        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_executable($binary)) {
            return false;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return false;
            }

            // From the application root, which is where Browsershot's own script resolves it from too.
            $resolve = new Process([$binary, '-e', "require.resolve('puppeteer')"], base_path());
            $resolve->setTimeout(5);
            $resolve->run();

            return $resolve->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
