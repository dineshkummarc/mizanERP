<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CompanyInvitations;
use App\Notifications\CompanyInvitation;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Inviting the accountant — `docs/bexio-gap-plan.md` Phase 2, item 10.
 *
 * The role and the membership existed; this is the front door. The assertion that matters is the
 * second one: somebody who already has an account elsewhere gets access and nothing else — no second
 * account, no employee record, no password reset they did not ask for.
 */
class UserInvitationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $inviter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inviter = $this->makeUser('Administrator', 'admin@test.local');
        $this->actingAs($this->inviter);
        $this->setCurrentTenant();

        Notification::fake();
    }

    public function test_a_new_person_gets_an_account_the_role_and_a_link_to_set_a_password(): void
    {
        $result = app(CompanyInvitations::class)->invite($this->tenant, 'sana@auditors.test', 'Sana Auditor', ['Accountant'], $this->inviter);

        $user = $result['user'];

        $this->assertTrue($result['created']);
        $this->assertSame('Sana Auditor', $user->name);
        $this->assertTrue($user->companies()->whereKey($this->tenant->getKey())->exists());
        $this->assertTrue($user->fresh()->hasRole('Accountant'));

        Notification::assertSentTo($user, CompanyInvitation::class, fn (CompanyInvitation $n): bool => $n->setsPassword
            && $n->roles === ['Accountant']
            && str_contains($n->url, 'password-reset'));
    }

    public function test_an_existing_account_is_given_access_and_nothing_else(): void
    {
        $existing = User::factory()->create(['email' => 'outside@firm.test', 'name' => 'Bilal', 'status' => 1]);
        $before = User::count();

        $result = app(CompanyInvitations::class)->invite($this->tenant, 'outside@firm.test', 'Ignored', ['Accountant'], $this->inviter);

        $this->assertFalse($result['created']);
        $this->assertSame($before, User::count());
        $this->assertSame('Bilal', $result['user']->name, 'the name on the form does not rename an account somebody already has');
        $this->assertTrue($existing->companies()->whereKey($this->tenant->getKey())->exists());
        $this->assertTrue($existing->fresh()->hasRole('Accountant'));

        Notification::assertSentTo($existing, CompanyInvitation::class, fn (CompanyInvitation $n): bool => ! $n->setsPassword);
    }

    public function test_a_role_the_company_does_not_have_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no role called Auditor');

        app(CompanyInvitations::class)->invite($this->tenant, 'x@y.test', 'X', ['Auditor'], $this->inviter);
    }

    public function test_the_invitation_is_sent_from_the_users_page(): void
    {
        Livewire::test(ListUsers::class)
            ->callAction('invite', data: ['name' => 'Sana Auditor', 'email' => 'sana@auditors.test', 'roles' => ['Accountant']])
            ->assertNotified('Invitation sent to sana@auditors.test.');

        $this->assertTrue(User::where('email', 'sana@auditors.test')->exists());
    }
}
