<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The versioned API surface — docs/desktop-mobile-plan.md A2/D4.
 *
 * Every route answers under both `/api` (deprecated, for clients in the field) and `/api/v1` (the surface
 * new work targets). The one shape that was allowed to change does so on `/v1` only: MPR's "no record for
 * comparison" is a 404 there and keeps its original 400 unprefixed.
 */
class ApiVersioningTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private \App\Modules\Core\Models\Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // An auth user before the tenant is set: Filament's TenantSet event needs one.
        $this->actingAs($this->makeUser('Administrator', 'api-setup@test.local'));
        $this->company = $this->setCurrentTenant();
    }

    public function test_login_answers_on_both_the_bare_and_the_v1_surface(): void
    {
        $this->makeUser('Employee', 'versioned@test.local');

        foreach (['/api/login', '/api/v1/login'] as $url) {
            $this->app['auth']->forgetGuards();

            $this->postJson($url, ['email' => 'versioned@test.local', 'password' => 'password', 'device_name' => 'Pixel'])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonStructure(['success', 'message', 'token', 'token_type', 'user']);
        }
    }

    public function test_an_authenticated_endpoint_answers_on_v1(): void
    {
        $user = $this->makeUser('Employee', 'v1-auth@test.local');
        \Laravel\Sanctum\Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/tokens')->assertOk()->assertJsonPath('success', true);
    }

    public function test_mpr_comparison_not_found_is_400_unprefixed_and_404_on_v1(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => 'mpr'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        // The comparison service refers to the MPR model by its App\Models alias, registered at boot in
        // production; make it resolvable here too.
        if (! class_exists('App\\Models\\Mpr')) {
            class_alias(\App\Modules\Mpr\Models\MPR::class, 'App\\Models\\Mpr');
        }

        $user = $this->makeUser('Employee', 'mpr-v@test.local');
        \Laravel\Sanctum\Sanctum::actingAs($user, ['api']);

        // No MPR records, so the comparison is empty — the status is the only difference between surfaces.
        $this->getJson('/api/my-mprs/comparison')->assertStatus(400)->assertJsonPath('success', false);
        $this->getJson('/api/v1/my-mprs/comparison')->assertStatus(404)->assertJsonPath('success', false);
    }
}
