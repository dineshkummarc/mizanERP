<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * nginx terminates TLS and forwards to Octane over plain HTTP on loopback, so
         * without this the framework sees an http:// request on 127.0.0.1 and generates
         * http:// URLs into an https page — assets and Livewire's update endpoint blocked
         * as mixed content. Under PHP-FPM the question never arose: fastcgi_params carried
         * HTTPS through, so this is a cost of the proxy, not of Octane itself.
         *
         * Loopback only. A wildcard here would let a client set X-Forwarded-Proto itself.
         */
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // A token must carry the `api` ability — docs/desktop-mobile-plan.md A1. On the group, so a route
        // file added later cannot forget it.
        $middleware->api(append: [App\Http\Middleware\RequireTokenAbility::class, 'throttle:api']);

        // Licensing is enforced per route, not globally: most routes belong to a
        // module, and the ones that do not (login, the panel shell, tenant file
        // downloads) must stay reachable whatever a company has bought.
        $middleware->alias([
            'module' => App\Http\Middleware\EnsureModuleEnabled::class,
            // For pages outside the panel, which have no tenant otherwise. List it
            // before `module:` — a licence belongs to a company, so one has to be
            // current before that question can be answered.
            'company' => App\Http\Middleware\ResolveCompanyFromRoute::class,
            // For the API, whose routes name no company: the caller's membership does. Same
            // ordering rule — after `auth:sanctum`, before `module:`.
            'api.company' => App\Http\Middleware\ResolveCompanyFromUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A no-op until SENTRY_LARAVEL_DSN is set (production only). Everything it
        // sends passes through App\Support\SentryScrubber first — see config/sentry.php.
        Sentry\Laravel\Integration::handles($exceptions);
    })->create();
