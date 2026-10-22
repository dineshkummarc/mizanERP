<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * A bank's statement CSV, read through a column mapping — `docs/bexio-gap-plan.md` Phase 3, item 12.
 *
 * Every bank lays its export out differently: a signed amount or a credit and a debit column, dates as
 * 22/10/2026 or 2026-10-22, the narration under "Particulars" or "Description". The mapping names the
 * columns by their header, and the first import against a bank account saves it there, so the second
 * statement from that bank is upload-and-done. What comes out is the shape
 * `BankReconciliationService::import()` has always taken.
 */
class StatementCsv
{
    /** The mapping keys, each the header of a column in the file; `date_format` is Carbon's, when needed. */
    public const FIELDS = ['date', 'description', 'reference', 'amount', 'credit', 'debit', 'date_format'];

    /**
     * @param  array<string, string|null>  $mapping  see FIELDS
     * @return array<int, array{transaction_date: string, description: string, reference: string, amount: float}>
     *
     * @throws InvalidArgumentException naming the column or line that could not be read
     */
    public static function rows(string $csv, array $mapping): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        if ($lines === [] || trim($lines[0]) === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        $header = array_map(fn ($h): string => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));

        $column = function (string $field) use ($mapping, $header): ?int {
            $name = trim((string) ($mapping[$field] ?? ''));

            if ($name === '') {
                return null;
            }

            $index = array_search(strtolower($name), $header, true);

            if ($index === false) {
                throw new InvalidArgumentException(
                    "No column called \"{$name}\" in the file. The header row has: ".implode(', ', $header).'.'
                );
            }

            return $index;
        };

        $date = $column('date') ?? throw new InvalidArgumentException('Say which column holds the date.');
        $description = $column('description');
        $reference = $column('reference');
        $amount = $column('amount');
        $credit = $column('credit');
        $debit = $column('debit');

        if ($amount === null && $credit === null && $debit === null) {
            throw new InvalidArgumentException(
                'Say which column holds the amount — one signed column, or a credit column and a debit column.'
            );
        }

        $rows = [];

        foreach ($lines as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $cols = str_getcsv($raw);
            $at = fn (?int $index): ?string => $index === null ? null : ($cols[$index] ?? null);

            $value = $amount !== null
                ? self::number($at($amount))
                : self::number($at($credit)) - self::number($at($debit));

            $rows[] = [
                'transaction_date' => self::date($at($date), $mapping['date_format'] ?? null, $i + 2),
                'description' => trim((string) $at($description)),
                'reference' => trim((string) $at($reference)),
                'amount' => round($value, 2),
            ];
        }

        return $rows;
    }

    /** "1,500.00", "(1,500.00)", "-1500", "1500 CR" — what banks actually print. */
    private static function number(?string $raw): float
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return 0.0;
        }

        $negative = str_starts_with($text, '(') || str_starts_with($text, '-') || str_ends_with(strtoupper($text), 'DR');
        $digits = preg_replace('/[^0-9.]/', '', $text) ?? '';

        return (float) $digits * ($negative ? -1 : 1);
    }

    private static function date(?string $raw, ?string $format, int $line): string
    {
        $text = trim((string) $raw);

        if ($text === '') {
            throw new InvalidArgumentException("Line {$line} has no date.");
        }

        try {
            $date = filled($format) ? Carbon::createFromFormat($format, $text) : Carbon::parse($text);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date) {
            throw new InvalidArgumentException(
                "Line {$line}: \"{$text}\" is not a date".(filled($format) ? " in the format {$format}" : '').'.'
            );
        }

        return $date->toDateString();
    }
}
