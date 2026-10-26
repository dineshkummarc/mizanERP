<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\Impersonation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Signing in as another user, so an administrator can finish work on behalf of
 * staff who cannot reasonably do it themselves.
 *
 * The negative assertions are the point of this file. A feature that hands one
 * person another person's session is only safe if the ways it must refuse are
 * pinned down, so most of what follows is about who cannot be impersonated and
 * what the record says afterwards.
 */
class ImpersonationTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private Impersonation $impersonation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->impersonation = app(Impersonation::class);

        // The suite runs on the array session driver, whose store lives for one
        // request — so the password hash AuthenticateSession writes never reaches
        // the next request and the logout this guards against cannot happen. A
        // real session driver is what makes these two tests able to fail.
        config(['session.driver' => 'file']);
    }

    private function member(string $role, array $attributes = []): User
    {
        // A password of their own. UserFactory hashes 'password' once and hands
        // every user the same hash, and AuthenticateSession decides whether a
        // session is still valid by comparing exactly that — so shared hashes
        // would let a stale stamp match the wrong person, hiding the bug the
        // "survives the next request" tests below exist to catch.
        $user = User::factory()->create($attributes + [
            'status' => 1,
            'password' => 'secret-'.uniqid(),
        ]);
        $this->company->users()->syncWithoutDetaching([$user->getKey()]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $user->assignRole($role);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    /** Standing where the installation is administered: no company in context. */
    private function onThePlatformPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();
    }

    // --- who may ------------------------------------------------------------

    public function test_an_administrator_can_sign_in_as_a_member_of_their_company(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);

        $this->assertTrue($this->impersonation->allows($admin, $employee));

        $this->impersonation->start($employee);

        $this->assertAuthenticatedAs($employee);
        $this->assertTrue($this->impersonation->isActive());
        $this->assertTrue($this->impersonation->impersonator()->is($admin));
    }

    /**
     * The invite-then-impersonate takeover is refused — security audit 2026-10-26, finding 1.
     *
     * An administrator of this company may not sign in as a user who also belongs to another company,
     * because impersonating them would hand the administrator that other company. A user who belongs only
     * here is still reachable.
     */
    public function test_cannot_impersonate_a_user_who_also_belongs_to_another_company(): void
    {
        $admin = $this->member('Administrator');
        $targetBothCompanies = $this->member('Employee');
        $otherCompany = Company::factory()->create();
        $targetBothCompanies->companies()->syncWithoutDetaching([$otherCompany->getKey()]);

        $onlyHere = $this->member('Employee');

        $this->actAs($admin);

        $this->assertFalse(
            $this->impersonation->allows($admin, $targetBothCompanies),
            'an admin must not reach a user who also belongs to another company',
        );
        $this->assertTrue(
            $this->impersonation->allows($admin, $onlyHere),
            'a user who belongs only to this company is still reachable',
        );
    }

    public function test_an_ordinary_employee_cannot_impersonate(): void
    {
        $employee = $this->member('Employee');
        $colleague = $this->member('Employee');

        $this->actAs($employee);

        $this->assertFalse($this->impersonation->allows($employee, $colleague));
    }

    public function test_a_super_admin_can_impersonate_without_the_permission(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $employee = $this->member('Employee');

        $this->actAs($superAdmin);

        $this->assertTrue($this->impersonation->allows($superAdmin, $employee));
    }

    /**
     * The reported case: a super admin switches to a company and every "Log in as"
     * button is gone. They are not a member of most companies — they switch into
     * any of them regardless — so requiring the shared-company check of them made
     * the feature vanish exactly where it was most wanted.
     */
    public function test_a_super_admin_can_sign_in_as_a_user_of_a_company_they_do_not_belong_to(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);

        $otherCompany = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompany->getKey());
        (new RoleSeeder)->run();

        $theirStaff = User::factory()->create(['status' => 1, 'password' => 'secret-'.uniqid()]);
        $otherCompany->users()->syncWithoutDetaching([$theirStaff->getKey()]);

        $this->assertFalse(
            $superAdmin->companies()->whereKey($otherCompany->getKey())->exists(),
            'the point of the case: no membership row, and none needed'
        );

        // Serving that company, as they would be after switching to it.
        $this->actingAs($superAdmin);
        $this->setCurrentTenant($otherCompany);

        $this->assertTrue($this->impersonation->allows($superAdmin, $theirStaff));

        $this->impersonation->start($theirStaff);
        $this->assertAuthenticatedAs($theirStaff);
    }

    /**
     * Reach follows the panel you are standing in.
     *
     * A super admin inside one customer's company is looking at that company's people, and
     * signing in as a different customer's staff from that URL is the confusion the platform
     * panel exists to remove. The reach itself is not lost — the test below exercises it
     * from where it now belongs.
     */
    public function test_a_super_admin_in_a_company_is_bound_to_that_companys_people(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);

        $otherCompany = Company::factory()->create();
        $theirStaff = User::factory()->create(['status' => 1, 'password' => 'secret-'.uniqid()]);
        $otherCompany->users()->syncWithoutDetaching([$theirStaff->getKey()]);

        $this->actAs($superAdmin);

        $this->assertFalse($this->impersonation->allows($superAdmin, $theirStaff));
    }

    /** And from the platform panel, where there is no company to narrow it. */
    public function test_a_super_admin_reaches_any_companys_user_from_the_platform_panel(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);

        $otherCompany = Company::factory()->create();
        $theirStaff = User::factory()->create(['status' => 1, 'password' => 'secret-'.uniqid()]);
        $otherCompany->users()->syncWithoutDetaching([$theirStaff->getKey()]);

        $this->actingAs($superAdmin);
        $this->onThePlatformPanel();

        $this->assertTrue($this->impersonation->allows($superAdmin, $theirStaff));

        // The swap lands them in a company the target can actually access — this panel
        // would refuse the person they have just become.
        $this->impersonation->start($theirStaff);

        $this->assertSame(
            Filament::getPanel('admin')->getUrl($otherCompany),
            Filament::getPanel('admin')->getUrl(),
        );
    }

    public function test_a_company_administrator_cannot_reach_the_platform_panel_to_widen_theirs(): void
    {
        // The reach above is a super admin's because the panel is. Nothing about
        // impersonation needs to re-state that, but it is worth pinning that the door is
        // what limits it.
        $admin = $this->member('Administrator');

        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('platform')));
    }

    /**
     * The limit that remains. A user belonging to no company has no panel to land
     * in, and being stuck as somebody else with nothing rendering is the failure
     * this feature must not produce.
     */
    public function test_even_a_super_admin_cannot_sign_in_as_a_user_of_no_company(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $orphan = User::factory()->create(['status' => 1, 'password' => 'secret-'.uniqid()]);

        $this->actAs($superAdmin);

        $this->assertFalse($this->impersonation->allows($superAdmin, $orphan));
    }

    // --- who may not be impersonated ----------------------------------------

    public function test_nobody_can_impersonate_a_super_admin(): void
    {
        // The escalation that matters: a company Administrator who could sign in
        // as a super admin would own the whole installation.
        $admin = $this->member('Administrator');
        $target = $this->member('Employee', ['is_super_admin' => true]);

        $this->actAs($admin);
        $this->assertFalse($this->impersonation->allows($admin, $target));

        // Not even another super admin, who gains nothing by it.
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $this->actAs($superAdmin);
        $this->assertFalse($this->impersonation->allows($superAdmin, $target));
    }

    public function test_an_administrator_cannot_reach_another_companys_user(): void
    {
        // Users are shared across companies, so without this an Administrator of
        // one company could sign in as somebody who happens to also work for
        // another — and land in that other company's data.
        $admin = $this->member('Administrator');

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->create(['status' => 1]);
        $otherCompany->users()->syncWithoutDetaching([$outsider->getKey()]);

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $outsider));
    }

    public function test_a_deactivated_user_cannot_be_impersonated(): void
    {
        // canAccessPanel() refuses status 0, so this would hand over a session
        // that is bounced straight back out.
        $admin = $this->member('Administrator');
        $inactive = $this->member('Employee', ['status' => 0]);

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $inactive));
    }

    public function test_you_cannot_impersonate_yourself(): void
    {
        $admin = $this->member('Administrator');

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $admin));
    }

    public function test_impersonation_cannot_be_nested(): void
    {
        // The way back is a single stored id, so a chain would make "who is really
        // doing this" unanswerable.
        $admin = $this->member('Administrator');
        $first = $this->member('Administrator');
        $second = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($first);

        $this->assertFalse($this->impersonation->allows($first, $second));
    }

    public function test_starting_an_unauthorized_impersonation_throws(): void
    {
        $employee = $this->member('Employee');
        $colleague = $this->member('Employee');

        $this->actAs($employee);

        $this->expectException(RuntimeException::class);

        $this->impersonation->start($colleague);
    }

    // --- getting back -------------------------------------------------------

    public function test_stopping_returns_to_the_original_account(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->assertTrue($this->impersonation->stop()->is($admin));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse($this->impersonation->isActive());
    }

    public function test_stopping_when_not_impersonating_does_nothing(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $this->assertNull($this->impersonation->stop());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_stop_route_returns_to_the_original_account(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->post(route('impersonate.stop'))->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    // --- staying signed in --------------------------------------------------

    /**
     * The swap has to outlive the request that made it.
     *
     * AuthenticateSession keeps a copy of the signed-in user's password hash in
     * the session and, the moment the two disagree, flushes the session and sends
     * the visitor to the login screen. That is what "Log in as" used to do: the
     * copy stayed the administrator's while the session became the employee's,
     * and the redirect afterwards was the first request to notice.
     *
     * Note the first request below. It is what puts the hash in the session in the
     * first place — the middleware writes it after a response — so a browser
     * always has one and actingAs() on its own never does. Every other test here
     * skipped it, which is why the whole feature could pass while being unusable.
     */
    public function test_signing_in_as_someone_survives_the_next_request(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);

        $url = Filament::getPanel('admin')->getUrl($this->company);

        $this->get($url)->assertOk();

        $this->impersonation->start($employee);

        $this->get($url)->assertOk();
        $this->assertAuthenticatedAs($employee);
    }

    /** And so does the way back. */
    public function test_returning_to_your_own_account_survives_the_next_request(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);

        $url = Filament::getPanel('admin')->getUrl($this->company);

        $this->get($url)->assertOk();
        $this->impersonation->start($employee);
        $this->get($url)->assertOk();

        $this->post(route('impersonate.stop'))->assertRedirect();

        $this->get($url)->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    // --- the record ---------------------------------------------------------

    public function test_starting_and_stopping_are_both_logged(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);
        $this->impersonation->stop();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Impersonation',
            'description' => "{$admin->email} started signing in as {$employee->email}",
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Impersonation',
            'description' => "{$admin->email} stopped signing in as {$employee->email}",
        ]);
    }

    /**
     * The integrity question this feature raises. Accepting a salary change is a
     * statement of consent, so a record showing only the employee would be a lie
     * about who agreed.
     */
    public function test_work_done_while_impersonating_names_the_real_actor(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        // Any audited change carries the stamp — it is applied by the Auditable
        // trait's log entry, which is the same path a payslip acknowledgement
        // takes, without needing payroll fixtures to prove it.
        $record = Beneficiary::create(['name' => 'Utility Co', 'payment_type' => 'IBFT']);

        $entry = \App\Modules\Core\Models\ActivityLog::query()
            ->whereMorphedTo('subject', $record)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'the change itself is audited');

        $stamp = $entry->properties['impersonated_by'] ?? null;

        $this->assertNotNull($stamp, 'and carries who was really at the keyboard');
        $this->assertSame($admin->getKey(), $stamp['id']);
        $this->assertSame($admin->email, $stamp['email']);
    }

    public function test_ordinary_work_is_not_stamped(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $record = Beneficiary::create(['name' => 'Landlord', 'payment_type' => 'IBFT']);

        $entry = \App\Modules\Core\Models\ActivityLog::query()
            ->whereMorphedTo('subject', $record)
            ->latest('id')
            ->first();

        $this->assertArrayNotHasKey('impersonated_by', $entry->properties->all());
    }

    // --- the banner ---------------------------------------------------------

    public function test_the_panel_shows_a_banner_and_a_way_back_while_impersonating(): void
    {
        // Acting as somebody else without realising it is the whole risk, so the
        // banner is asserted on a real panel response rather than trusted.
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->get(\Filament\Facades\Filament::getPanel('admin')->getUrl($this->company))
            ->assertOk()
            ->assertSee('You are signed in as', escape: false)
            ->assertSee($employee->name, escape: false)
            ->assertSee('Stop impersonating', escape: false)
            ->assertSee(route('impersonate.stop'), escape: false);
    }

    public function test_the_banner_is_absent_when_not_impersonating(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $this->get(\Filament\Facades\Filament::getPanel('admin')->getUrl($this->company))
            ->assertOk()
            ->assertDontSee('Stop impersonating');
    }

    // --- the button ---------------------------------------------------------

    public function test_the_action_is_offered_only_for_rows_that_may_be_impersonated(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $inactive = $this->member('Employee', ['status' => 0]);

        $this->actAs($admin);

        Livewire::test(ListUsers::class)
            ->assertActionVisible(TestAction::make('impersonate')->table($employee->getKey()))
            ->assertActionHidden(TestAction::make('impersonate')->table($inactive->getKey()))
            ->assertActionHidden(TestAction::make('impersonate')->table($admin->getKey()))
            // Stronger than hiding the action: a platform account is not in a
            // company's user list at all (UserResource::exceptPlatformAdmins).
            ->assertCanNotSeeTableRecords([$superAdmin]);
    }
}
