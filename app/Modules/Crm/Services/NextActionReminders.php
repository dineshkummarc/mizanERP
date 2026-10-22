<?php

namespace App\Modules\Crm\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Filament\Resources\NextActions\NextActionResource;
use App\Modules\Crm\Models\NextAction;
use App\Notifications\NextActionDue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Telling people what falls due today — `docs/bexio-gap-plan.md` Phase 2, item 6, and the one
 * notification `docs/crms-plan.md` §9 asked for that was never built.
 *
 * **One message per person per day, listing everything**, not one per action: §9's rule, and the
 * health-check alerts' lesson — a job that mails the same warning every morning trains somebody to
 * filter it. An action is reminded once per effective due date (`reminded_on`); a snooze moves the
 * date, so the reminder comes again when the snooze ends, and an overdue action that was already
 * reminded is left to the list's own badge.
 */
class NextActionReminders
{
    /**
     * Which open actions have come due and not been reminded for this due date.
     *
     * @return Collection<int, NextAction>
     */
    public function due(?string $on = null): Collection
    {
        $on = Carbon::parse($on ?: now()->toDateString())->toDateString();

        return NextAction::query()
            ->due($on)
            ->where(fn ($q) => $q
                ->whereNull('reminded_on')
                ->whereRaw('reminded_on < COALESCE(snoozed_until, due_on)', [], 'or'))
            // Only somebody who can be told: an assignee with an account.
            ->whereHas('assignee', fn ($q) => $q->whereNotNull('user_id'))
            ->with(['assignee.user', 'subject'])
            ->orderBy('due_on')
            ->get();
    }

    /**
     * Send today's digests and record that they went.
     *
     * Marked after the notification is queued, so a send that throws is tried again tomorrow rather
     * than recorded as done — the same order the overdue reminders use.
     *
     * @return Collection<int, NextAction> what was reminded
     */
    public function send(?string $on = null): Collection
    {
        $on = Carbon::parse($on ?: now()->toDateString())->toDateString();
        $due = $this->due($on);

        $due->groupBy('assignee_employee_id')->each(function (Collection $actions) use ($on): void {
            $user = $actions->first()->assignee->user;

            $user->notify(new NextActionDue($actions->values(), $this->listUrl()));

            NextAction::query()->whereKey($actions->modelKeys())->update(['reminded_on' => $on]);
        });

        return $due;
    }

    /**
     * The worked list, for the button. Null when the panel cannot be addressed from here — a command
     * with no current company — and the email then simply has no button.
     */
    private function listUrl(): ?string
    {
        try {
            return NextActionResource::getUrl('index', panel: 'admin', tenant: Company::current());
        } catch (\Throwable) {
            return null;
        }
    }
}
