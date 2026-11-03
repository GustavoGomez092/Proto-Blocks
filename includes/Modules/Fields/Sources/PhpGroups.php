<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Sources;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;

/**
 * Field groups declared in PHP with proto_field_group(). A static store, like
 * Phase 1's PhpDeclarations, so calls a theme makes before the Fields module
 * boots are kept. The index is built once per request (on the first field
 * read after `init` 10, or at the latest by meta registration on `init` 20);
 * groups added later are not seen. Declare them from functions.php or on
 * `init` before priority 10.
 *
 * `$target` names where the group shows:
 *   '<post type>'         implied `post_type == <post type>`
 *   'taxonomy:<taxonomy>' implied `taxonomy == <taxonomy>`
 *   'options:<page>'      implied `options_page == <page>`
 *   ''                    the group carries its own `location`
 * Any other target is an `invalid_target` error and the group is skipped.
 */
final class PhpGroups
{
    private const POST_TYPE = '/^[a-z][a-z0-9_-]{0,19}$/D';
    private const TAXONOMY = '/^taxonomy:([a-z][a-z0-9_-]{0,31})$/D';
    private const OPTIONS = '/^options:([a-z][a-z0-9-]{0,63})$/D';

    /** @var list<array{target: string, group: array<string|int, mixed>, source: string}> */
    private static array $groups = [];

    /** @param array<string|int, mixed> $group */
    public static function add(string $target, array $group, string $source): void
    {
        self::$groups[] = ['target' => $target, 'group' => $group, 'source' => $source];
    }

    /** @return list<array{target: string, group: array<string|int, mixed>, source: string}> */
    public static function all(): array
    {
        return self::$groups;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$groups = [];
    }

    /**
     * The implied location of a target: null for '' (the group's own
     * location), false when the target is not valid.
     *
     * @return array<string, string>|false|null
     */
    public static function impliedLocation(string $target): array|false|null
    {
        if ($target === '') {
            return null;
        }
        if (preg_match(self::TAXONOMY, $target, $m) === 1) {
            return ['taxonomy' => $m[1]];
        }
        if (preg_match(self::OPTIONS, $target, $m) === 1) {
            return ['options_page' => $m[1]];
        }
        if (preg_match(self::POST_TYPE, $target) === 1) {
            return ['post_type' => $target];
        }

        return false;
    }

    /**
     * Every stored group normalised: FieldGroup source 'php', path the
     * "<file>:<line>" of the call. One report per call.
     *
     * @return array{groups: list<FieldGroup>, reports: list<array{source: string, report: Report}>}
     */
    public static function normalized(): array
    {
        $groups = [];
        $reports = [];
        foreach (self::$groups as $entry) {
            $implied = self::impliedLocation($entry['target']);
            if ($implied === false) {
                $report = new Report();
                $report->addError('', 'invalid_target', sprintf(
                    /* translators: %s: the first argument of proto_field_group() */
                    __('The proto_field_group() target "%s" is not a post type slug, "taxonomy:<slug>", "options:<page>" or "".', 'proto-blocks'),
                    $entry['target']
                ));
                $reports[] = ['source' => $entry['source'], 'report' => $report];
                continue;
            }
            $result = GroupNormalizer::normalizeMany($entry['group'], 'php', $entry['source'], $implied);
            array_push($groups, ...$result['groups']);
            $reports[] = ['source' => $entry['source'], 'report' => $result['report']];
        }

        return ['groups' => $groups, 'reports' => $reports];
    }
}
