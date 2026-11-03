<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Collects proto_post_type() / proto_taxonomy() / proto_options_page() calls made by the theme.
 *
 * Declarations are accepted until their kind is sealed: post types and
 * taxonomies when post types register (`init` priority 9), options pages when
 * their menus are built (`admin_menu` priority 10). A later call is ignored
 * with a `declared_too_late` warning.
 */
final class PhpDeclarations
{
    /** @var array<string, array<string, Declaration>> */
    private static array $declarations = [];

    /** @var list<Issue> */
    private static array $issues = [];

    /** @var array<string, true> sealed kinds */
    private static array $sealed = [];

    /**
     * @param string               $kind   Declaration::TYPE, Declaration::TAXONOMY or Declaration::OPTIONS
     * @param string               $name   Human singular name (the page title for an options page).
     * @param array<string, mixed> $args   Any key a declaration file accepts.
     * @param string               $source "<file>:<line>" of the call.
     */
    public static function add(string $kind, string $name, array $args, string $source): void
    {
        if (!in_array($kind, [Declaration::TYPE, Declaration::TAXONOMY, Declaration::OPTIONS], true)) {
            throw new \InvalidArgumentException("Unknown declaration kind \"$kind\".");
        }

        if (isset(self::$sealed[$kind])) {
            self::$issues[] = new Issue(
                Issue::WARNING,
                'declared_too_late',
                $kind === Declaration::OPTIONS
                    ? sprintf(
                        /* translators: 1: declared page title, 2: call site */
                        __('"%1$s" was declared at %2$s after the settings pages were added to the admin menu and was ignored. Declare it before the admin_menu action (priority 9 at the latest), for example in functions.php.', 'proto-blocks'),
                        $name,
                        $source
                    )
                    : sprintf(
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

        $slug = $args['slug'] ?? Slug::fromName($name, Slug::max($kind));
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

        // An options page is named by its `title`, like its page.jsonc.
        $nameKey = $kind === Declaration::OPTIONS ? 'title' : 'name';
        $data = [$nameKey => $name] + $args;
        $data[$nameKey] = $name;

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
        return self::$declarations + [Declaration::TYPE => [], Declaration::TAXONOMY => [], Declaration::OPTIONS => []];
    }

    /** @return list<Issue> */
    public static function issues(): array
    {
        return self::$issues;
    }

    /**
     * Stop accepting declarations of $kind; without one, post types and
     * taxonomies (options pages stay open until `admin_menu`).
     */
    public static function seal(?string $kind = null): void
    {
        foreach ($kind === null ? [Declaration::TYPE, Declaration::TAXONOMY] : [$kind] as $one) {
            self::$sealed[$one] = true;
        }
    }

    /** Tests only: forget every declaration and issue, and unseal. */
    public static function reset(): void
    {
        self::$declarations = [];
        self::$issues = [];
        self::$sealed = [];
    }
}
