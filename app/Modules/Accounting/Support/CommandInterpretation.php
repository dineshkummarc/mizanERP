<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Models\TransactionType;
use Illuminate\Support\Carbon;

/**
 * What one command was understood to mean, and whether that is enough to act on.
 *
 * This is the object the confirmation panel renders and the booker consumes. It is deliberately a single
 * value rather than a resolved-command-or-error pair: a half-resolved command is the *normal* case worth
 * showing (three slots filled, one question), not an error state to be collapsed into a message.
 */
class CommandInterpretation
{
    /**
     * @param  array<int, string>  $questions  what the user must answer before this can be booked
     * @param  array<int, string>  $flags  slots the model guessed at rather than read
     */
    public function __construct(
        public readonly CommandUtterance $utterance,
        /** Which resolver produced this — see {@see CommandResolver::key()}. */
        public readonly ?string $resolverKey = null,
        /**
         * The confirmation's first line, built by the resolver.
         *
         * Here as a string rather than computed, because what "the effect" means differs per resolver:
         * money leaving a cash account, a claim awaiting approval, a cost landing on a job. The one rule
         * that survives across all of them is §2.3's — it states what will happen, never the word the
         * user typed — and that rule belongs to whoever knows the domain.
         */
        public readonly ?string $effect = null,
        /** Resolver-specific slots that are not shared columns. Written to `command_utterances.resolved`. */
        public readonly array $payload = [],
        public readonly ?string $direction = null,
        public readonly ?float $amount = null,
        public readonly ?TransactionType $category = null,
        public readonly ?Account $registerAccount = null,
        public readonly ?Carbon $date = null,
        public readonly ?string $description = null,
        public readonly array $questions = [],
        /**
         * Outstanding slots that can be answered from a list, as `slot => the question`.
         *
         * A subset of `questions`, carrying the one extra fact the confirmation needs to be useful: *which
         * slot* is missing, so it can offer that slot's values instead of only stating the problem.
         * "How much?" is absent by design — an amount is not a menu, and a question with no list stays a
         * question.
         *
         * @var array<string, string>
         */
        public readonly array $unresolved = [],
        public readonly array $flags = [],
        public readonly float $confidence = 0.0,
    ) {}

    /** Every slot filled and nothing outstanding. */
    public function isComplete(): bool
    {
        // The resolver decides what "enough" means by whether it asked anything. A cash command needs a
        // register account and an expense claim needs an employee; neither fact belongs here.
        return $this->questions === [] && $this->effect !== null;
    }

    /**
     * Whether Enter alone may confirm this — §6.
     *
     * Large amounts need the figure clicked, not because the machine is less sure of them but because the
     * cost of being wrong scales with them. Note direction is never a reason to withhold this: by §2.1 it
     * is determined by the rules rather than inferred, so it is displayed prominently and confirmed like
     * everything else.
     */
    public function needsExplicitConfirmation(): bool
    {
        return $this->flags !== []
            || ($this->amount ?? 0) >= (float) config('ai.commands.confirm_threshold');
    }

    /**
     * The confirmation's first line, and the most important string in the feature.
     *
     * It states the **effect** rather than echoing the user's word: "money out — 25,000 leaves Cash /
     * Bank", never "credit 25,000". §2.3 is why — the rule is unambiguous but a speaker using
     * bank-statement English may not be, and this line is the only place they can catch it.
     */
    public function effectLine(): string
    {
        return $this->effect ?: 'Not enough to act on yet.';
    }

    /** The supporting line: what it was filed against, and when. */
    public function detailLine(): string
    {
        $parts = [];

        if ($this->category) {
            $parts[] = $this->category->name
                .($this->category->account ? " ({$this->category->account->code} {$this->category->account->name})" : '');
        }

        if ($this->date) {
            $parts[] = $this->date->isToday()
                ? 'today, '.$this->date->format('j M Y')
                : $this->date->format('j M Y');
        }

        return implode(' · ', $parts);
    }
}
