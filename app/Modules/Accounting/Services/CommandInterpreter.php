<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Support\CommandGrammar;
use App\Modules\Accounting\Support\CommandInterpretation;
use App\Modules\Accounting\Support\CommandResolver;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use App\Support\TenantTransaction;

/**
 * Sentence in, {@see CommandInterpretation} out — `docs/ai-command-bot-plan.md` §3, §7.
 *
 * **A router, not a translator.** It picks the resolver an utterance belongs to, asks it for its schema
 * and its half of the prompt, makes one model call, and hands the reply back to the same resolver. It
 * knows nothing about cash, claims, or any module's slots — §7's "the bot routes; it does not learn every
 * module's schema", enforced by having nowhere to put that knowledge.
 *
 * Two properties fall out of routing *before* the model call rather than inside it. The prompt only ever
 * carries one resolver's reference data, so it stays small; and a module this tenant has not licensed
 * contributes nothing to it at all, so the reply cannot leak the module list.
 */
class CommandInterpreter
{
    /** @param array<int, CommandResolver> $resolvers */
    public function __construct(
        private readonly StructuredModel $model,
        private readonly array $resolvers,
    ) {}

    /**
     * @param  string|null  $transcript  what a recogniser heard, when the command was dictated (§5)
     * @param  string|null  $locale  the dictation language, so a badly-transcribing one is visible
     */
    public function interpret(
        string $utterance,
        ?int $userId = null,
        ?string $transcript = null,
        ?string $locale = null,
    ): CommandInterpretation {
        $utterance = trim($utterance);
        $userId ??= auth()->id();

        $row = TenantTransaction::run(fn (): CommandUtterance => CommandUtterance::create([
            'user_id' => $userId,
            'utterance' => $utterance,
            // Stored even when it equals the utterance. "Dictated and accepted unchanged" and "typed" are
            // different facts, and a null transcript is how the second is told from the first — collapsing
            // them would make the speech-error rate unmeasurable.
            'transcript' => $transcript,
            'locale' => $locale,
            'outcome' => CommandUtterance::OUTCOME_NEEDS_INPUT,
        ]));

        if ($utterance === '') {
            return $this->fail($row, 'Nothing was said.');
        }

        $resolver = $this->route($utterance);

        if ($resolver === null) {
            return $this->fail(
                $row,
                'There is nothing here that can act on a command. Check that Accounting is switched on for '
                .'this company, or use the entry screens.'
            );
        }

        try {
            $parsed = $this->model->extract(
                $resolver->promptSection(),
                // Today's date rides in the user turn, never the system prompt: in the prompt it would
                // move the cached prefix at midnight and throw away every tenant's cache at once.
                sprintf("Today is %s.\n\nCommand: %s", now()->toDateString(), $utterance),
                $this->schemaFor($resolver),
            );
        } catch (ModelUnavailable $e) {
            return $this->fail($row, $e->getMessage());
        }

        $row->update(['parsed' => $parsed, 'resolver' => $resolver->key()]);

        return $resolver->resolve($row, $parsed);
    }

    /** The resolvers this tenant and this user can actually reach. */
    public function available(): array
    {
        return array_values(array_filter(
            $this->resolvers,
            fn (CommandResolver $r): bool => $r->isAvailable(),
        ));
    }

    public function resolverFor(?string $key): ?CommandResolver
    {
        foreach ($this->available() as $resolver) {
            if ($resolver->key() === $key) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * Which resolver an utterance belongs to.
     *
     * Pattern matching against each resolver's `claims()`, then the default. Deliberately crude, and the
     * tradeoff is worth naming: a routing pass through the model would read the sentence properly and
     * double the latency and cost of every command. Patterns plus a default is the cheap majority case,
     * and when it routes wrong the user sees a proposal for the wrong kind of thing and cancels — a
     * visible failure rather than a silent one, which is the only kind this feature can afford.
     */
    private function route(string $utterance): ?CommandResolver
    {
        $available = $this->available();
        $default = null;

        foreach ($available as $resolver) {
            foreach ($resolver->claims() as $pattern) {
                if (preg_match('/'.$pattern.'/iu', $utterance)) {
                    return $resolver;
                }
            }

            if ($resolver->isDefault()) {
                $default = $resolver;
            }
        }

        // No default available (Accounting off, say) but something else is: use it rather than refuse.
        return $default ?? ($available[0] ?? null);
    }

    /**
     * The resolver's slots plus the envelope every reply carries.
     *
     * @return array<string, mixed>
     */
    private function schemaFor(CommandResolver $resolver): array
    {
        ['properties' => $properties, 'required' => $required] = $resolver->schemaProperties();

        $envelope = CommandGrammar::envelopeProperties(array_keys($properties));

        return [
            'type' => 'object',
            'additionalProperties' => false,
            // Every slot is required in the schema so the model must answer for each — nullable in type,
            // so "I could not tell" is a first-class answer rather than a missing key.
            'required' => [...array_keys($properties), ...array_keys($envelope)],
            'properties' => [...$properties, ...$envelope],
        ];
    }

    private function fail(CommandUtterance $row, string $reason): CommandInterpretation
    {
        $row->update([
            'outcome' => CommandUtterance::OUTCOME_FAILED,
            'outcome_reason' => $reason,
        ]);

        return new CommandInterpretation(
            utterance: $row->refresh(),
            questions: [$reason],
        );
    }
}
