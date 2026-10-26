<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token must carry the `api` ability to use the API — docs/desktop-mobile-plan.md, A1.
 *
 * Applied to the whole `api` middleware group rather than to each module's route file, so a route added
 * later cannot forget it. Asked of the Sanctum guard directly, because this runs before the route's own
 * `auth:sanctum`. Only a personal access token is checked: a panel session reaching the API has no token
 * and is not the subject here, and a token issued before abilities existed carries `*`, which passes.
 */
class RequireTokenAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user('sanctum')?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can(AuthController::ABILITY)) {
            abort(403, 'This token may not use the API.');
        }

        return $next($request);
    }
}
