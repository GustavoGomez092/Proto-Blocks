<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API\Stubs;

use ProtoBlocks\Modules\Redirects\Import\PluginImporterInterface;

/** A plugin importer with fixed data, for the redirects route and command tests. */
final class FakeImporter implements PluginImporterInterface
{
    public int $previews = 0;

    /**
     * @param array<string, array<string, mixed>> $groups slug => raw group
     * @param list<array<string, mixed>>          $skipped
     * @param list<string>|null                   $plugins null: no plugins() override
     */
    public function __construct(
        private readonly string $id,
        private readonly bool $available = true,
        private readonly array $groups = [],
        private readonly array $skipped = [],
        private readonly ?array $plugins = null
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return ucfirst($this->id);
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function preview(): array
    {
        $this->previews++;

        return $this->groups;
    }

    public function cleanupHint(): ?string
    {
        return 'Deactivate ' . $this->id . '.';
    }

    /** @return list<array<string, mixed>> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function plugins(): array
    {
        return $this->plugins ?? ['fake/' . $this->id . '.php'];
    }
}
