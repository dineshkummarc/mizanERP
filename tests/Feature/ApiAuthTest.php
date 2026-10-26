<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\AccountingTestCase;

/**
 * The API's sign-in and token lifecycle — docs/desktop-mobile-plan.md, A1, "one test file pinning each
 * behavior": the throttle, the device name, expiry, the one ability, logout, refresh, the device list and
 * revoking one device from another, and a login response that is a deliberate shape rather than the model.
 */
class ApiAuthTest extends AccountingTestCase
{
    private Company $company;

    private User $user;

    /**
     * Nobody is signed in to a session here, deliberately: Sanctum's guard answers with the session user
     * before it looks at a bearer token, so a test acting as somebody would never exercise the token.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = $this->member('phone@test.local');
    }

    private function member(string $email): User
    {
        $user = User::create(['name' => 'Member', 'email' => $email, 'password' => bcrypt('password'), 'status' => 1]);
        $user->companies()->attach($this->company->getKey());

        return $user;
    }

    private function login(array $overrides = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', $overrides + [
            'email' => 'phone@test.local',
            'password' => 'password',
            'device_name' => 'Pixel 8',
        ]);
    }

    /**
     * A request carrying this token, as a client would send it.
     *
     * The test client keeps the authenticated guards between requests in one test, so a token revoked
     * by the last request would still look signed in to the next. Forgetting them is what a new HTTP
     * request does on its own.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_a_login_names_its_device(): void
    {
        $this->login(['device_name' => null])->assertStatus(422)->assertJsonValidationErrors('device_name');
    }

    public function test_a_login_issues_a_named_expiring_token_with_one_ability(): void
    {
        $response = $this->login()->assertOk()->assertJsonPath('success', true)->assertJsonPath('token_type', 'Bearer');

        $this->assertNotNull($response->json('expires_at'));

        $token = PersonalAccessToken::query()->where('tokenable_id', $this->user->getKey())->firstOrFail();
        $this->assertSame('Pixel 8', $token->name);
        $this->assertSame(['api'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(now()->addMinutes((int) config('sanctum.expiration'))->timestamp, $token->expires_at->timestamp, 5);

        // A deliberate shape, not the model: nothing a client does not need, and the companies it can name.
        $user = $response->json('user');
        $this->assertSame(['id', 'name', 'email', 'companies'], array_keys($user));
        $this->assertSame($this->company->getKey(), $user['companies'][0]['id']);
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('remember_token', $user);
    }

    public function test_a_login_is_throttled_per_email_and_address(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->login(['password' => 'wrong'])->assertStatus(401);
        }

        $this->login(['password' => 'wrong'])->assertStatus(429);

        // The key is the email and the address together: another account from here is still served.
        $this->member('other@test.local');
        $this->login(['email' => 'other@test.local', 'password' => 'wrong'])->assertStatus(401);
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $this->user->forceFill(['status' => 0])->save();

        $this->login()->assertStatus(403);
    }

    public function test_logout_revokes_the_token_that_made_the_request(): void
    {
        $token = $this->login()->json('token');

        $this->as($token)->getJson('/api/tokens')->assertOk();
        $this->as($token)->postJson('/api/logout')->assertOk();
        $this->as($token)->getJson('/api/tokens')->assertUnauthorized();
    }

    public function test_refresh_replaces_the_token_and_keeps_the_device_name(): void
    {
        $old = $this->login()->json('token');

        $new = $this->as($old)->postJson('/api/refresh')->assertOk()->json('token');

        $this->as($old)->getJson('/api/tokens')->assertUnauthorized();
        $this->as($new)->getJson('/api/tokens')->assertOk()->assertJsonPath('data.0.device_name', 'Pixel 8');
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $this->user->getKey())->count());
    }

    public function test_devices_are_listed_and_one_can_be_signed_out_from_another(): void
    {
        $phone = $this->login()->json('token');
        $tablet = $this->login(['device_name' => 'iPad'])->json('token');

        $list = $this->as($phone)->getJson('/api/tokens')->assertOk()->assertJsonPath('count', 2)->json('data');
        $byName = collect($list)->keyBy('device_name');
        $this->assertTrue($byName['Pixel 8']['current']);
        $this->assertFalse($byName['iPad']['current']);

        $this->as($phone)->deleteJson('/api/tokens/'.$byName['iPad']['id'])->assertOk();
        $this->as($tablet)->getJson('/api/tokens')->assertUnauthorized();

        // Somebody else's device is not yours to sign out.
        $other = $this->member('other@test.local');
        $theirs = $other->createToken('Their phone', ['api']);
        $this->as($phone)->deleteJson('/api/tokens/'.$theirs->accessToken->getKey())->assertNotFound();
    }

    public function test_a_token_without_the_api_ability_is_refused_and_a_legacy_token_is_not(): void
    {
        $narrow = $this->user->createToken('scoped', ['read-only'])->plainTextToken;
        $this->as($narrow)->getJson('/api/tokens')->assertForbidden();

        // Issued before abilities existed: `*`, which still passes — the field builds keep working.
        $legacy = $this->user->createToken('API Token')->plainTextToken;
        $this->as($legacy)->getJson('/api/tokens')->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $expired = $this->user->createToken('old phone', ['api'], now()->subMinute())->plainTextToken;

        $this->as($expired)->getJson('/api/tokens')->assertUnauthorized();
    }
}
