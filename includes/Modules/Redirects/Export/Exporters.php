<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

/**
 * The exporters (spec §9), by REST/CLI format name.
 */
final class Exporters
{
    /** @return list<ExporterInterface> */
    public static function all(): array
    {
        return [new CsvExporter(), new JsonExporter(), new ApacheExporter(), new NginxExporter(), new NetlifyExporter()];
    }

    /** csv | json | apache | nginx | redirects */
    public static function byFormat(string $format): ?ExporterInterface
    {
        foreach (self::all() as $exporter) {
            if ($exporter->format() === $format) {
                return $exporter;
            }
        }
        return null;
    }
}
