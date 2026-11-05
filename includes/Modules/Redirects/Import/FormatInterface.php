<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * A file format the importer reads. Parsers never execute anything, never
 * throw on bad input (a bad line is an `invalid` row), and refuse input over
 * Formats::MAX_BYTES with a single `too_large` row.
 */
interface FormatInterface
{
    /** csv | json | apache | redirects */
    public function id(): string;

    /** True when $filename decides for this format or, with an undecided filename, the content looks like it. */
    public function detect(string $filename, string $text): bool;

    /** @return list<array{rule: array, status: string, reason: ?string, line: int}> */
    public function parse(string $text): array;
}
