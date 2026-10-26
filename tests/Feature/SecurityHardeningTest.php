<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\FinalSettlement;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The lower-severity fixes from the 2026-10-26 audit, pinned: a suspended company admits nobody (12),
 * super-admin cannot be granted by a non-super-admin (13), final-settlement figures are downline-scoped
 * (9), and streamed files carry the anti-script headers (5).
 */
class SecurityHardeningTest extends AccountingTestCase
{
    use InteractsWithTenant;

    public function test_a_suspended_company_admits_nobody(): void
    {
        $company = Company::factory()->create(['status' => 1]);
        $user = User::factory()->create(['status' => 1]);
        $user->companies()->attach($company->getKey());

        $this->assertTrue($user->canAccessTenant($company));

        $company->update(['status' => 0]);

        $this->assertFalse($user->fresh()->canAccessTenant($company->fresh()));
        $this->assertTrue(
            User::factory()->create(['is_super_admin' => true])->canAccessTenant($company->fresh()),
            'a super admin still reaches a suspended company',
        );
    }

    public function test_a_non_super_admin_cannot_grant_super_admin(): void
    {
        $this->actingAs($this->makeUser('Administrator', 'admin@test.local'));

        $target = User::factory()->create(['is_super_admin' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('grant super-admin');

        $target->update(['is_super_admin' => true]);
    }

    public function test_a_super_admin_may_grant_super_admin(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($actor);

        $target = User::factory()->create(['is_super_admin' => false]);
        $target->update(['is_super_admin' => true]);

        $this->assertTrue($target->fresh()->is_super_admin);
    }

    public function test_final_settlements_are_visible_only_down_the_hierarchy(): void
    {
        $this->actingAs($this->makeUser('Administrator', 'settle-admin@test.local'));
        $this->setCurrentTenant();

        // A non-privileged viewer (Employee role) who nonetheless holds SettlementView via a custom grant.
        $viewer = $this->makeUser('Employee', 'viewer@test.local');
        $viewer->givePermissionTo('SettlementView');
        $own = Employee::create(['user_id' => $viewer->id, 'employee_id' => 'E1', 'gender' => 'Male', 'is_active' => true]);
        $report = Employee::create(['employee_id' => 'E2', 'gender' => 'Male', 'is_active' => true, 'manager_id' => $own->id]);
        $stranger = Employee::create(['employee_id' => 'E3', 'gender' => 'Male', 'is_active' => true]);

        $mine = FinalSettlement::create(['employee_id' => $report->id, 'left_on' => '2026-10-01']);
        $theirs = FinalSettlement::create(['employee_id' => $stranger->id, 'left_on' => '2026-10-01']);

        $this->assertTrue($viewer->can('view', $mine), 'a settlement in the downline is visible');
        $this->assertFalse($viewer->can('view', $theirs), 'a settlement outside the downline is not');
    }

    public function test_the_file_stream_carries_the_anti_script_headers(): void
    {
        $user = $this->makeUser('Administrator', 'files@test.local');
        $this->actingAs($user);
        $this->setCurrentTenant();
        $root = \App\Support\TenantStorage::publicRoot($this->tenant);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($root.'/mprs');
        \Illuminate\Support\Facades\File::put($root.'/mprs/x.pdf', '%PDF-x');

        $response = $this->get('/files/'.$this->tenant->getKey().'/mprs/x.pdf');

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }
}
