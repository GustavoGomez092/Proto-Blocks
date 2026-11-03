<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Collects proto_post_type() / proto_taxonomy() calls made by the theme.
 *
 * Declarations are accepted until seal() (called when post types register at
 * `init` priority 9); a later call is ignored with a `declared_too_late` warning.
 */
final class PhpDeclarations
{
    /** @var array<string, array<string, Declaration>> */
    private static array $declarations = [];

    /** @var list<Issue> */
    private static array $issues = [];

    private static bool $sealed = false;

    /**
     * @param string               $kind   Declaration::TYPE or Declaration::TAXONOMY
     * @param string               $name   Human singular name.
     * @param array<string, mixed> $args   Any key a declaration file accepts.
     * @param string               $source "<file>:<line>" of the call.
     */
    public static function add(string $kind, string $name, array $args, string $source): void
    {
        if ($kind !== Declaration::TYPE && $kind !== Declaration::TAXONOMY) {
            throw new \InvalidArgumentException("Unknown declaration kind \"$kind\".");
        }

        if (self::$sealed) {
            self::$issues[] = new Issue(
                Issue::WARNING,
                'declared_too_late',
                sprintf(
                    /* translators: 1: declared name, 2: call site */
                    __('"%1$s" was declared at %2$s after post types were registered and was ignored. Declare it before the init action (priority 9), for example in functions.php.', 'proto-blocks'),
                    $name,
                    $source
                ),
                $source,
                null,
                $kind
            );
            return;
        }

        $max = $kind === Declaration::TYPE ? Slug::TYPE_MAX : Slug::TAXONOMY_MAX;
        $slug = $args['slug'] ?? Slug::fromName($name, $max);
        $slug = is_string($slug) ? $slug : '';

        $slugError = Slug::error($slug, $kind);
        if ($slugError !== null) {
            self::$issues[] = new Issue(
                Issue::ERROR,
                'slug_' . $slugError,
                FileLoader::slugMessage($slugError, $slug, $kind),
                $source,
                $slug === '' ? null : $slug,
                $kind
            );
            return;
        }

        $data = ['name' => $name] + $args;
        $data['name'] = $name;

        $previous = self::$declarations[$kind][$slug] ?? null;
        if ($previous !== null) {
            self::$issues[] = new Issue(
                Issue::WARNING,
                'duplicate_php',
                sprintf(
                    /* translators: 1: slug, 2: earlier call site, 3: later call site */
                    __('"%1$s" is declared in PHP twice: %2$s and %3$s. The later call is used.', 'proto-blocks'),
                    $slug,
                    $previous->source,
                    $source
                ),
                $source,
                $slug,
                $kind
            );
        }

        self::$declarations[$kind][$slug] = new Declaration($kind, $slug, $data, Declaration::ORIGIN_PHP, $source, null);
    }

    /**
     * @return array<string, array<string, Declaration>> keyed kind => slug
     */
    public static function all(): array
    {
        return self::$declarations + [Declaration::TYPE => [], Declaration::TAXONOMY => []];
    }

    /** @return list<Issue> */
    public static function issues(): array
    {
        return self::$issues;
    }

    public static function seal(): void
    {
        self::$sealed = true;
    }

    /** Tests only: forget every declaration and issue, and unseal. */
    public static function reset(): void
    {
        self::$declarations = [];
        self::$issues = [];
        self::$sealed = false;
    }
}
