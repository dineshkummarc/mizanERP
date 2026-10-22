<?php

namespace App\Support\Ai;

/**
 * A queue of canned replies, for tests and for local work without a key.
 *
 * The point is that everything downstream of the model — resolution, the sign
 * rules, the confirmation, the booking — is deterministic and can be asserted
 * exactly. The one genuinely non-deterministic part of this feature is the
 * sentence-to-fields step, and it is the only part this stands in for.
 */
class FakeStructuredModel implements StructuredModel
{
    /** @var array<int, array<string, mixed>|ModelUnavailable> */
    private array $queue = [];

    /** @var array<int, array{system: string, utterance: string, media_type?: string, bytes?: int}> */
    public array $calls = [];

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Queue the next reply. Arrays are returned; a ModelUnavailable is thrown.
     *
     * @param  array<string, mixed>|ModelUnavailable  $reply
     */
    public function queue(array|ModelUnavailable $reply): self
    {
        $this->queue[] = $reply;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extract(string $system, string $utterance, array $schema): array
    {
        $this->calls[] = ['system' => $system, 'utterance' => $utterance];

        return $this->next($utterance);
    }

    /**
     * The same queue; the call records what kind of document it was shown and how big.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extractFromDocument(string $system, string $instruction, string $bytes, string $mediaType, array $schema): array
    {
        $this->calls[] = ['system' => $system, 'utterance' => $instruction, 'media_type' => $mediaType, 'bytes' => strlen($bytes)];

        return $this->next($instruction);
    }

    /** @return array<string, mixed> */
    private function next(string $utterance): array
    {
        if ($this->queue === []) {
            throw new ModelUnavailable(
                'FakeStructuredModel had no queued reply for: '.$utterance
                .' — queue one with ->queue([...]) before the call.'
            );
        }

        $next = array_shift($this->queue);

        if ($next instanceof ModelUnavailable) {
            throw $next;
        }

        return $next;
    }
}
