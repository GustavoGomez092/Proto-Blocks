<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Reads another redirect plugin's configuration (never its hit counts) into
 * raw groups. Reads are prepared queries or options; the REST/CLI layer
 * checks `manage_options` before calling preview().
 */
interface PluginImporterInterface
{
    public function id(): string;

    public function label(): string;

    /** Cheap: the source table or option exists and holds something. */
    public function available(): bool;

    /** @return array<string, array> slug => raw group (`title`, `enabled`, `position`, `rules` of raw rules) */
    public function preview(): array;

    /** What to do with the source plugin once the import is verified, or null. */
    public function cleanupHint(): ?string;
}
