<?php

namespace App\Filament\Livewire;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Services\CommandBooker;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Accounting\Support\CommandInterpretation;
use App\Support\Ai\StructuredModel;
use Filament\Notifications\Notification;
use Livewire\Component;
use Throwable;

/**
 * The ⌘J command bar — `docs/ai-command-bot-plan.md` §6, §8.
 *
 * Beside the ⌘K palette rather than inside it, and §8 gives the reason: the palette *navigates* — every
 * provider returns an item with a URL — while this proposes an action and waits. That second beat is a
 * thing the palette has no concept of, so it gets its own component and shares the palette's render hook.
 *
 * **Nothing here books anything.** It calls {@see CommandInterpreter}, renders what came back, and calls
 * {@see CommandBooker} only when somebody presses Confirm. The gate is the whole point (§6): `bookRow()`
 * approves and posts on the spot, so there is no draft to reconsider afterwards.
 */
class CommandBar extends Component
{
    public string $utterance = '';

    /**
     * What the recogniser heard, before the person edited it — §5.
     *
     * Kept apart from `$utterance` rather than merged into it, because the two failure modes have to stay
     * distinguishable: a transcript that differs from what was finally submitted is a *speech* error, and
     * one that matches while the interpretation is wrong is a *parse* error. One field holding whichever
     * happened would make them the same event after the fact, and §5 requires that they not be.
     */
    public ?string $transcript = null;

    /** The dictation language last used, recorded so a locale that transcribes badly is visible as such. */
    public ?string $speechLocale = null;

    /** The audit row being confirmed. The row is the state — see CommandInterpretation::rehydrate(). */
    public ?int $utteranceId = null;

    /** @var array<string, mixed>|null Plain strings for the view; models do not survive the round trip. */
    public ?array $preview = null;

    public bool $busy = false;

    /**
     * What the user picked for a slot the parser could not fill, as slot => value.
     *
     * @var array<string, string>
     */
    public array $answers = [];

    /**
     * Answering a question re-resolves the command through the resolver that asked it.
     *
     * Re-running `resolve()` rather than patching the proposal in place is what keeps the answer honest:
     * the picked value goes back through the same tenant lookup, the same validation and the same
     * effect-line rules as a parsed one, so a chosen category cannot reach the ledger by a route a parsed
     * one could not.
     */
    public function updatedAnswers(mixed $value, string $slot): void
    {
        $row = $this->row();

        if ($row === null || $value === '' || $value === null) {
            return;
        }

        $resolver = app(CommandInterpreter::class)->resolverFor($row->resolver);

        if ($resolver === null) {
            return;
        }

        /*
         * Only ever a value this resolver offered.
         *
         * A <select> is a browser control and its options are whatever the browser says they are — the
         * value arriving here is user input, not a menu choice, however it was rendered. Checking it
         * against `choices()` is the same re-fetch-inside-the-tenant rule §8 applies to model replies,
         * for the same reason.
         */
        if (! array_key_exists($value, $resolver->choices($slot))) {
            return;
        }

        $parsed = $row->parsed ?? [];
        $parsed[$slot] = $value;
        $row->update(['parsed' => $parsed]);

        $this->preview = $this->present($resolver->resolve($row->refresh(), $parsed));
    }

    /**
     * Off unless switched on and configured, so the trigger opens nothing rather than something broken.
     *
     * **Static as well as instance** because two things have to agree on the answer: this component, which
     * decides whether the dialog exists, and the topbar trigger, which decides whether a button that opens
     * it exists. When they disagreed the result was the defect this method was extracted for — a button
     * with no dialog, or a dialog with no way in.
     */
    public static function available(): bool
    {
        if (! config('ai.enabled') || ! app(StructuredModel::class)->isConfigured()) {
            return false;
        }

        /*
         * The permission question must not be able to break the page this sits in.
         *
         * This is rendered from the panel's topbar, so it runs on **every screen** — and
         * `JournalEntryPolicy::create()` asks `hasPermissionTo()`, which throws
         * `PermissionDoesNotExist` for a name the permissions table has not got. On a half-seeded
         * install — a new module whose seeder has not run yet, a restore taken mid-deploy — that
         * exception is not a command bar that fails to appear, it is a 500 on the dashboard, on
         * every list, and on the profile page somebody is trying to change their password from.
         * `PasswordChangeTest` caught exactly that.
         *
         * Rescued to a denial rather than reported, because the rest of this application already
         * takes that position: `ModuleAuthorization` treats a permission it cannot resolve as a
         * refusal, and a command bar is a convenience — if authorization cannot be determined, it is
         * not offered.
         */
        return rescue(
            fn (): bool => (bool) auth()->user()?->can('create', \App\Modules\Accounting\Models\JournalEntry::class),
            false,
            report: false,
        );
    }

    public function isAvailable(): bool
    {
        return static::available();
    }

    /** Whether the mic is offered at all. Feature detection in the browser decides the rest. */
    public function voiceEnabled(): bool
    {
        return (bool) config('ai.voice.enabled');
    }

    /** @return array<string, string> */
    public function speechLocales(): array
    {
        return (array) config('ai.voice.locales', []);
    }

    /**
     * Take a transcript from the recogniser — and stop there.
     *
     * **It lands in the input for the person to read and edit; it is not interpreted.** This is the whole
     * of §5's requirement. Acting on what the machine thought it heard is what makes a mis-transcription
     * indistinguishable from a mis-parse, and on a poorly-supported locale mis-transcription is the
     * likelier of the two.
     */
    public function dictated(string $text, ?string $locale = null): void
    {
        $this->utterance = trim($text);
        $this->transcript = trim($text);
        $this->speechLocale = $locale;

        // A new dictation replaces any proposal on screen: the old preview belongs to different words.
        $this->reset('preview', 'utteranceId', 'answers');
    }

    public function interpret(): void
    {
        $this->reset('preview', 'utteranceId', 'answers');

        if (trim($this->utterance) === '') {
            return;
        }

        $this->busy = true;

        try {
            $interpretation = app(CommandInterpreter::class)->interpret(
                $this->utterance,
                transcript: $this->transcript,
                locale: $this->speechLocale,
            );
        } catch (Throwable $e) {
            report($e);
            $this->busy = false;
            $this->preview = ['error' => 'Something went wrong reading that. Try again, or use the entry screens.'];

            return;
        }

        $this->busy = false;
        $this->utteranceId = $interpretation->utterance->id;
        $this->preview = $this->present($interpretation);
    }

    public function confirm(): void
    {
        $interpretation = $this->current();

        if (! $interpretation) {
            $this->preview = ['error' => 'That command is no longer waiting to be confirmed.'];

            return;
        }

        try {
            $entry = app(CommandBooker::class)->book($interpretation);
        } catch (Throwable $e) {
            $this->preview = ['error' => $e->getMessage()];

            return;
        }

        Notification::make()
            ->success()
            ->title('Booked')
            // The effect, not the command — the same rule as the confirmation line itself (§2.3). The
            // reference is whatever the result numbers itself by: an entry, an invoice, a quote.
            ->body($interpretation->effectLine().' · '.($entry->entry_number ?? $entry->invoice_number ?? $entry->number ?? ''))
            ->send();

        $this->reset('utterance', 'transcript', 'speechLocale', 'preview', 'utteranceId', 'answers');
        $this->dispatch('command-booked');
    }

    public function cancel(): void
    {
        if ($interpretation = $this->current()) {
            app(CommandBooker::class)->cancel($interpretation, 'dismissed from the command bar');
        }

        $this->reset('utterance', 'transcript', 'speechLocale', 'preview', 'utteranceId', 'answers');
    }

    /**
     * The row being confirmed, re-read and re-scoped.
     *
     * Scoped to the signed-in user as well as the tenant: the id travels in component state, and a
     * component property is something the browser can change. Without this, a swapped id would confirm
     * somebody else's pending command.
     */
    private function current(): ?CommandInterpretation
    {
        $row = $this->row();

        if (! $row) {
            return null;
        }

        // Through the resolver that produced it: rebuilding a proposal is domain work (an employee here,
        // a register account there), and the component must not learn either shape.
        return app(CommandInterpreter::class)->resolverFor($row->resolver)?->rehydrate($row);
    }

    /**
     * The audit row this component is working on, re-read and re-scoped on every use.
     *
     * Scoped to the signed-in user as well as the tenant, because the id travels in component state and a
     * component property is something the browser can change. Without this, a swapped id would let one
     * user answer — or confirm — another's pending command.
     */
    private function row(): ?CommandUtterance
    {
        if ($this->utteranceId === null) {
            return null;
        }

        return CommandUtterance::query()
            ->whereKey($this->utteranceId)
            ->where('user_id', auth()->id())
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(CommandInterpretation $interpretation): array
    {
        $pickers = $this->pickers($interpretation);

        return [
            'effect' => $interpretation->effectLine(),
            'detail' => $interpretation->detailLine(),
            'description' => $interpretation->description,
            // Only the questions with nothing to answer them. A slot that got a picker states its question
            // as the picker's label, and printing it twice reads as two separate problems.
            'questions' => array_values(array_diff(
                $interpretation->questions,
                array_column($pickers, 'question'),
            )),
            'pickers' => $pickers,
            'flags' => $interpretation->flags,
            'complete' => $interpretation->isComplete(),
            'explicit' => $interpretation->needsExplicitConfirmation(),
            'error' => null,
        ];
    }

    /**
     * A list to answer each outstanding question with, where the resolver can offer one.
     *
     * The component asks by slot name and renders whatever comes back — it never learns that a cash
     * command has a direction or that a claim has an employee. That is the same seam §7 draws for the
     * prompt and the schema, extended to the one place the user has to make up for what the parser could
     * not read.
     *
     * @return array<int, array{slot: string, question: string, options: array<int|string, string>}>
     */
    private function pickers(CommandInterpretation $interpretation): array
    {
        $resolver = app(CommandInterpreter::class)->resolverFor($interpretation->resolverKey);

        if ($resolver === null) {
            return [];
        }

        $pickers = [];

        foreach ($interpretation->unresolved as $slot => $question) {
            $options = $resolver->choices($slot);

            // A slot with no closed list stays a plain question — there is no menu for "How much?".
            if ($options !== []) {
                $pickers[] = ['slot' => $slot, 'question' => $question, 'options' => $options];
            }
        }

        return $pickers;
    }

    public function render()
    {
        return view('filament.livewire.command-bar');
    }
}
