<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\TransactionType;
use App\Support\Ai\StructuredModel;
use Illuminate\Support\Carbon;

/**
 * The command parser with no model behind it — `docs/ai-command-bot-plan.md` §4, local driver.
 *
 * **This is the default, and the API is the upgrade.** Not a fallback or a degraded mode: for the commands
 * this feature was actually asked for — "rent 25000 out", "کرایہ ۲۵ ہزار دیا" — three or four tokens with
 * one job each, it is *better* than a model call on every axis that matters here:
 *
 *  - **Deterministic.** The same words always produce the same entry. For something that posts money that
 *    is worth more than flexibility; a model that is right 98% of the time is a ledger with an unexplained
 *    2% in it.
 *  - **Free, instant, offline.** No key, no per-command cost, no round trip, nothing to rate-limit, and it
 *    keeps working when the network does not.
 *  - **Readable.** When it mis-parses, the reason is a word list somebody can look at and a rule somebody
 *    can change. A model's mistake is not inspectable.
 *
 * Every part it needs already existed and none of it was written for this class: {@see CommandGrammar}'s
 * `DIRECTION_WORDS` is a map, {@see AmountWords} is a pure parser, and the category aliases are a table.
 * The model was only ever deciding *which token is which* — and in a command this short, position and a
 * word list decide that.
 *
 * **Where it genuinely loses.** Free-form sentences: "I paid the landlord twenty-five thousand yesterday
 * for the new office" needs to know that "the landlord" implies rent and that "twenty-five thousand" is a
 * number. This returns null for the category there and asks, which is the right failure — but a model
 * would have answered. Switch drivers when commands start looking like sentences rather than commands.
 *
 * It implements {@see StructuredModel} and returns the same shape, so nothing downstream knows the
 * difference: the resolvers, the sign rules, the confirmation and the booking are unchanged.
 *
 * **In Accounting rather than beside the interface it implements, and `ModuleBoundaryTest` is why.** This
 * started in `App\Support\Ai`, next to `Claude` and `StructuredModel` — and it is the one driver in that
 * directory that is not generic: it reads {@see CommandGrammar}'s direction words, {@see AmountWords},
 * and `transaction_types`. Shared code that needs a module's classes is that module's code, or every other
 * module depends on Accounting through the back door. The *interface* stays shared, which is the part that
 * has to be: a second driver for another module's commands would implement the same one from its own
 * directory. The container binding lives in a provider, which is the layer that exists to wire modules
 * together and is exempt for that reason.
 */
class LocalPatternModel implements StructuredModel
{
    /** No key, no network, nothing to configure. */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * A word list cannot read a photograph. Says so, naming the driver that can, rather than returning
     * nulls that would become a draft bill with nothing on it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extractFromDocument(string $system, string $instruction, string $bytes, string $mediaType, array $schema): array
    {
        throw new \App\Support\Ai\ModelUnavailable(
            'Reading a receipt needs the Claude driver: set AI_DRIVER=claude and ANTHROPIC_API_KEY. '
            .'The local parser handles typed commands only.'
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extract(string $system, string $utterance, array $schema): array
    {
        // The router prepends "Today is ...". Strip it: it is context for a model, not part of the command.
        $text = trim((string) preg_replace('/^Today is \d{4}-\d{2}-\d{2}\.\s*/u', '', $utterance));
        $text = trim((string) preg_replace('/^Command:\s*/iu', '', $text));

        $wanted = array_keys($schema['properties'] ?? []);
        $ambiguity = [];

        $out = [
            'confidence' => 1.0,
            'ambiguity' => [],
        ];

        if (in_array('direction', $wanted, true)) {
            $out['direction'] = $this->direction($text);
        }

        if (in_array('amount', $wanted, true)) {
            $out['amount'] = $this->amount($text);
        }

        if (in_array('transaction_type_code', $wanted, true)) {
            $out['transaction_type_code'] = $this->category($text, $schema);
        }

        if (in_array('employee_id', $wanted, true)) {
            $out['employee_id'] = $this->employee($text, $schema);
        }

        if (in_array('date', $wanted, true)) {
            [$date, $guessed] = $this->date($text);
            $out['date'] = $date;

            if ($guessed) {
                $ambiguity[] = 'date';
            }
        }

        if (in_array('description', $wanted, true)) {
            // No memo is invented. The resolver falls back to the category name, which is more honest than
            // echoing the raw command into the ledger's description column.
            $out['description'] = null;
        }

        $out['ambiguity'] = $ambiguity;

        // Confidence is a real signal even here: a slot this could not fill is a slot the confirmation
        // must ask about, and the resolvers already read `null` that way.
        $filled = count(array_filter($out, fn ($v, $k) => $v !== null && ! in_array($k, ['confidence', 'ambiguity'], true), ARRAY_FILTER_USE_BOTH));
        $out['confidence'] = $wanted === [] ? 1.0 : round($filled / count($wanted), 2);

        return $out;
    }

    /**
     * The direction word, from {@see CommandGrammar::DIRECTION_WORDS}.
     *
     * Whole-word matching, longest first, so "credited" does not match on "credit" inside another word and
     * so a two-word phrase wins over a one-word one. Returns null when the command carries no directional
     * word at all — which the resolver turns into "Was this money in or money out?" rather than a guess.
     */
    private function direction(string $text): ?string
    {
        $words = CommandGrammar::DIRECTION_WORDS;

        uksort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($words as $word => $direction) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                return $direction;
            }
        }

        return null;
    }

    /**
     * The amount, handed to the parser that already understands "25k", "1.5 lakh" and "۲۵ ہزار".
     *
     * The number plus any scale word immediately after it — so "rent 25 hazaar out" yields "25 hazaar"
     * rather than "25". Returns the fragment unparsed, exactly as the schema asks: {@see AmountWords} does
     * the arithmetic later, in one place, where its refusals already have good messages.
     */
    private function amount(string $text): ?string
    {
        // A run of digits (either numeral system) with optional grouping and decimals, plus a trailing
        // scale word if one follows.
        $scales = 'k|thousand|hazaar|hazar|ہزار|lakh|lac|lakhs|لاکھ|crore|karor|kror|کروڑ';
        $digits = '[0-9۰-۹٠-٩]';

        /*
         * The scale word must be followed by a non-letter, and that guard is load bearing.
         *
         * Without it the `k` alternative matches the leading letter of the *next word*: "petrol 3000
         * kharch" captured "3000 k" and booked 3,000,000 — a thousand times the intended figure, and
         * silently, because 3,000,000 is a perfectly valid amount. Every Urdu direction word beginning with
         * k is a live case (kharch, and "kal" in a date phrase).
         */
        if (! preg_match(
            '/('.$digits.'[\d۰-۹٠-٩,٬]*(?:[.٫]'.$digits.'+)?)(?:\s*('.$scales.')(?![\p{L}\p{N}]))?/iu',
            $text,
            $m,
        )) {
            return null;
        }

        return trim($m[1].' '.($m[2] ?? ''));
    }

    /**
     * The category, matched against this tenant's own names and aliases.
     *
     * The schema's enum is the allowed set, so this can never return a code the resolver would reject —
     * the same guarantee the model has, enforced here by only ever choosing from that list.
     *
     * Longest alias first, because "gas bill" must beat "gas" and "rent received" must beat "rent". A
     * shorter alias winning is how "rent received" would book as money out.
     *
     * @param  array<string, mixed>  $schema
     */
    private function category(string $text, array $schema): ?string
    {
        $allowed = $this->enumFor($schema, 'transaction_type_code');

        if ($allowed === []) {
            return null;
        }

        $candidates = [];

        foreach (TransactionType::query()->with('aliases')->whereIn('code', $allowed)->get() as $type) {
            $candidates[mb_strtolower($type->name)] = $type->code;
            $candidates[mb_strtolower($type->code)] = $type->code;

            foreach ($type->aliases as $alias) {
                $candidates[$alias->alias] = $type->code;
            }
        }

        uksort($candidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($candidates as $needle => $code) {
            if ($needle !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * An employee named in the command, matched on any part of the name.
     *
     * **Refuses on a tie.** Two employees whose names both appear — "Ali" matching both Ali Raza and Ali
     * Khan — returns null and lets the confirmation ask, rather than picking the first row. Guessing which
     * person is claiming is not a guess worth making.
     *
     * @param  array<string, mixed>  $schema
     */
    private function employee(string $text, array $schema): ?int
    {
        $allowed = $this->enumFor($schema, 'employee_id');

        if ($allowed === []) {
            return null;
        }

        $matches = [];

        foreach (\App\Modules\Employees\Models\Employee::query()->whereIn('id', $allowed)->get() as $employee) {
            $name = (string) ($employee->full_name ?? $employee->name);

            foreach (preg_split('/\s+/u', mb_strtolower($name)) ?: [] as $part) {
                if (mb_strlen($part) < 3) {
                    continue;
                }

                if (preg_match('/(?<![\p{L}])'.preg_quote($part, '/').'(?![\p{L}])/iu', $text)) {
                    $matches[$employee->id] = true;
                    break;
                }
            }
        }

        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    /**
     * Relative date words. Returns [iso, wasGuessed].
     *
     * **"kal" (کل) is both yesterday and tomorrow**, and a three-word command carries no tense to settle
     * it. Resolved to yesterday — a command about a transaction is nearly always about one that has
     * happened — and flagged, so the confirmation makes somebody look at it.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function date(string $text): array
    {
        $today = Carbon::today();

        $map = [
            'aaj' => [0, false], 'آج' => [0, false], 'today' => [0, false],
            'yesterday' => [-1, false],
            'tomorrow' => [1, false],
            'parson' => [-2, true], 'پرسوں' => [-2, true],
            'kal' => [-1, true], 'کل' => [-1, true],
        ];

        uksort($map, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($map as $word => [$offset, $guessed]) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/iu', $text)) {
                return [$today->copy()->addDays($offset)->toDateString(), $guessed];
            }
        }

        // An explicit ISO date, if somebody typed one.
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            return [$m[1], false];
        }

        return [null, false];
    }

    /**
     * The allowed values for a slot, read out of the schema the resolver built.
     *
     * Reading them from the schema rather than re-querying is what keeps this driver honest: the resolver
     * has already decided what this tenant and this user may choose, including every module gate, and this
     * chooses only from that.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, mixed>
     */
    private function enumFor(array $schema, string $slot): array
    {
        $property = $schema['properties'][$slot] ?? null;

        if ($property === null) {
            return [];
        }

        foreach ($property['anyOf'] ?? [] as $branch) {
            if (isset($branch['enum'])) {
                return array_values(array_filter($branch['enum'], fn ($v) => $v !== null));
            }
        }

        return array_values(array_filter($property['enum'] ?? [], fn ($v) => $v !== null));
    }
}
