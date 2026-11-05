<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

/**
 * Renders normalised groups (contract shape) to a file. `$hits` is
 * `array<group slug, array<rule id, array{hits: int, lastHit: ?string}>>`.
 */
interface ExporterInterface
{
    /** csv | json | apache | nginx | redirects */
    public function format(): string;

    public function mime(): string;

    /** File extension without the dot; '' when the file has a fixed name (`_redirects`). */
    public function extension(): string;

    /** @param list<array> $groups @param array<string, array<string, array>> $hits */
    public function render(array $groups, array $hits): string;
}
