<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Modules\Core\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The API's sign-in and token lifecycle — docs/desktop-mobile-plan.md, A1.
 *
 * What it replaces: a login with no throttle, tokens that never expired, carried every ability and were
 * all named "API Token", no way to sign out, and the whole user model in the response. A phone lost in a
 * rickshaw was a permanent credential. Now the login is rate-limited per email and address
 * (AppServiceProvider's `login` limiter), each token is named for its device, expires after
 * `sanctum.expiration` minutes and carries one ability that RequireTokenAbility checks on every API
 * request, a device can be signed out or revoked, and the response says only what a client needs.
 */
class AuthController extends Controller
{
    /** What a token issued here may do. Checked by RequireTokenAbility on every API request. */
    public const ABILITY = 'api';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        // Validated, not logged in: the API has no session, and attempt() would sign the web guard in — which
        // in a test client outlives the request and then answers for every bearer token after it.
        $guard = Auth::guard('web');

        if (! $guard->validate(['email' => $data['email'], 'password' => $data['password']])) {
            return ApiResponse::error('Invalid email or password', 401);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if ((int) $user->status !== 1) {
            return ApiResponse::error('This account is disabled.', 403);
        }

        // Second factor, for accounts the panels force it on (administrators). Without this the API handed a
        // phished password a full token that could name any tenant — security audit 2026-10-26, finding 3.
        if ($user->mustUseMultiFactorAuthentication()) {
            if (blank($user->getAppAuthenticationSecret())) {
                return ApiResponse::error('Set up two-factor authentication in the web app before signing in through the API.', 403, ['mfa_setup_required' => true]);
            }

            if (! $this->secondFactorPasses($user, (string) $request->input('code', ''))) {
                return ApiResponse::error('A valid two-factor code is required.', 401, ['mfa_required' => true]);
            }
        }

        return $this->issued($user, $data['device_name'], 'Login successful');
    }

    /**
     * A TOTP code, or a recovery code, against the user's enrolled app-authentication secret.
     *
     * Reuses Filament's own verifier (the same window and single-use enforcement the panel applies), so a
     * code accepted here cannot be replayed and the API and the panel agree on what a valid code is.
     */
    private function secondFactorPasses(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $provider = AppAuthentication::make();

        if ($provider->verifyCode($code, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true)) {
            return true;
        }

        try {
            return $user instanceof HasAppAuthenticationRecovery && $provider->verifyRecoveryCode($code, $user);
        } catch (\Throwable) {
            // No recovery codes enrolled: a wrong TOTP is simply wrong, not a 500.
            return false;
        }
    }

    /** Sign this device out: the token that made the request stops working. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(['message' => 'Signed out; this token no longer works.']);
    }

    /** A fresh token for the same device, the old one revoked — how a client outlives `sanctum.expiration`. */
    public function refresh(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $deviceName = $current instanceof PersonalAccessToken ? $current->name : 'API Token';

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return $this->issued($request->user(), $deviceName, 'Token refreshed');
    }

    /** Every device signed in to this account. */
    public function tokens(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->getKey(),
                'device_name' => $token->name,
                'current' => $token->getKey() === $currentId,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->values();

        return ApiResponse::success(['count' => $devices->count(), 'data' => $devices]);
    }

    /** Sign out one device — the lost phone — from another. */
    public function revoke(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        abort_unless($deleted, 404, 'No such device.');

        return ApiResponse::success(['message' => 'That device is signed out.']);
    }

    private function issued(User $user, string $deviceName, string $message): JsonResponse
    {
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;

        $token = $user->createToken($deviceName, [self::ABILITY], $expiresAt);

        return ApiResponse::success(['message' => $message,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => new AuthenticatedUserResource($user)]);
    }
}
