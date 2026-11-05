<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * The plugin importers (spec §9).
 */
final class PluginImporters
{
    /** @return list<PluginImporterInterface> */
    public static function all(): array
    {
        return [
            new RedirectionImporter(),
            new Simple301Importer(),
            new SafeRedirectManagerImporter(),
            new RankMathImporter(),
            new YoastImporter(),
            new OldSlugsImporter(),
        ];
    }

    /** @return list<PluginImporterInterface> the importers whose source has data */
    public static function available(): array
    {
        return array_values(array_filter(self::all(), static fn(PluginImporterInterface $i): bool => $i->available()));
    }

    public static function byId(string $id): ?PluginImporterInterface
    {
        foreach (self::all() as $importer) {
            if ($importer->id() === $id) {
                return $importer;
            }
        }
        return null;
    }
}
