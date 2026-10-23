<?php

namespace App\Support\WhatsApp;

use Closure;
use LogicException;

/**
 * A document to send, offered both ways, because the two providers want opposite
 * things.
 *
 * Meta takes the file: it is uploaded for a media id and the message refers to
 * that. Twilio takes a link: it fetches the file itself from a `MediaUrl`, and
 * checks the content-type header there before accepting the message.
 *
 * Both are lazy on purpose. The payslip PDF is rendered from the payslip as it
 * stands and never stored, so whichever the driver asks for is produced at the
 * moment it is asked for — and the one the driver does not use costs nothing.
 */
class WhatsAppDocument
{
    /**
     * @param  Closure(): string  $bytes  the file itself
     * @param  (Closure(): string)|null  $url  where a provider may fetch it; null
     *                                         when there is no way to publish it
     */
    public function __construct(
        public readonly string $filename,
        private readonly Closure $bytes,
        private readonly ?Closure $url = null,
    ) {}

    public function bytes(): string
    {
        return ($this->bytes)();
    }

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    /**
     * @throws LogicException when this document cannot be published — a sender
     *                        that needs a URL has to fail loudly rather than send
     *                        a message with nothing attached to it
     */
    public function url(): string
    {
        if ($this->url === null) {
            throw new LogicException(
                "The {$this->filename} document has no URL to fetch it from, and this provider sends media by link."
            );
        }

        return ($this->url)();
    }
}
