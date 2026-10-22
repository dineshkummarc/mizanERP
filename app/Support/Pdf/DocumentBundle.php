<?php

namespace App\Support\Pdf;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Several rendered documents, handed over as one zip — `docs/bexio-gap-plan.md` Phase 5, item 18.
 *
 * The certificates were issued one employee at a time from each employee's own page. A year's worth for
 * every employee is the same documents, and the only thing a bulk action needs that a single one did not
 * is somewhere to put thirty PDFs. Built in a temporary file and streamed, so the memory cost is one
 * document at a time rather than all of them.
 */
class DocumentBundle
{
    /** @var array<string, string> filename => bytes */
    private array $files = [];

    public function add(string $filename, string $bytes): self
    {
        // Two employees with the same name produce the same filename; the second must not replace the
        // first inside the archive.
        $name = $filename;
        $n = 2;

        while (isset($this->files[$name])) {
            $name = preg_replace('/(\.[a-z0-9]+)$/i', "-{$n}$1", $filename) ?: "{$filename}-{$n}";
            $n++;
        }

        $this->files[$name] = $bytes;

        return $this;
    }

    public function count(): int
    {
        return count($this->files);
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    /** The archive's bytes. */
    public function zip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bundle-').'.zip';
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip archive.');
        }

        foreach ($this->files as $name => $bytes) {
            $archive->addFromString($name, $bytes);
        }

        $archive->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** As a download. `streamDownload`, which is what Livewire turns into one. */
    public function download(string $zipName): StreamedResponse
    {
        $contents = $this->zip();

        return response()->streamDownload(
            fn () => print $contents,
            str_ends_with($zipName, '.zip') ? $zipName : $zipName.'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }
}
