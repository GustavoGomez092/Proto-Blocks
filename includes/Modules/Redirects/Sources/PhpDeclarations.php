<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Sources;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;

/**
 * proto_redirect() and proto_redirect_group() calls. Accepted until the
 * module seals them at `init` priority 9; a later call is ignored with a
 * `declared_too_late` warning. Single rules join the virtual group `php`
 * ("Declared in PHP", position 100). PHP declarations are trusted (no
 * UntrustedKeys stripping) but validated like files by the Registry.
 */
final class PhpDeclarations
{
    public const VIRTUAL_SLUG = 'php';
    public const VIRTUAL_POSITION = 100;

    /** @var list<array{rule: array<string|int, mixed>, source: string}> */
    private static array $rules = [];

    /** @var array<string, array{slug: string, data: array<string|int, mixed>, source: string}> */
    private static array $groups = [];

    /** @var list<Issue> */
    private static array $issues = [];

    private static bool $sealed = false;

    /** @param array<string|int, mixed> $rule */
    public static function addRule(array $rule, string $source): void
    {
        if (self::tooLate((string) ($rule['from'] ?? ''), $source)) {
            return;
        }
        self::$rules[] = ['rule' => $rule, 'source' => $source];
    }

    /** @param array<string|int, mixed> $group same keys as a group file, plus an optional `slug` */
    public static function addGroup(string $title, array $group, string $source): void
    {
        if (self::tooLate($title, $source)) {
            return;
        }
        $slug = $group['slug'] ?? Slug::fromName($title, 60);
        $slug = is_string($slug) ? $slug : '';
        unset($group['slug']);
        if (preg_match(GroupValidator::SLUG_PATTERN, $slug) !== 1 || $slug === self::VIRTUAL_SLUG) {
            self::$issues[] = new Issue(Issue::ERROR, 'invalid_slug', sprintf(
                /* translators: 1: group title, 2: derived slug, 3: call site */
                __('The redirect group "%1$s" (slug "%2$s") declared at %3$s was ignored: the slug must start with a lowercase letter, use only a-z, 0-9, "_" and "-", and may not be "settings" or "php".', 'proto-blocks'),
                $title,
                $slug,
                $source
            ), $source, $slug === '' ? null : $slug, GroupFiles::KIND);
            return;
        }
        if (isset(self::$groups[$slug])) {
            self::$issues[] = new Issue(Issue::WARNING, 'duplicate_php', sprintf(
                /* translators: 1: slug, 2: earlier call site, 3: later call site */
                __('The redirect group "%1$s" is declared in PHP twice: %2$s and %3$s. The later call is used.', 'proto-blocks'),
                $slug,
                self::$groups[$slug]['source'],
                $source
            ), $source, $slug, GroupFiles::KIND);
        }
        $group['title'] = is_string($group['title'] ?? null) && $group['title'] !== '' ? $group['title'] : $title;
        self::$groups[$slug] = ['slug' => $slug, 'data' => $group, 'source' => $source];
    }

    /** @return list<array{rule: array<string|int, mixed>, source: string}> */
    public static function rules(): array
    {
        return self::$rules;
    }

    /** @return array<string, array{slug: string, data: array<string|int, mixed>, source: string}> */
    public static function groups(): array
    {
        return self::$groups;
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

    public static function isSealed(): bool
    {
        return self::$sealed;
    }

    /** Tests only: forget every declaration and issue, and unseal. */
    public static function reset(): void
    {
        self::$rules = [];
        self::$groups = [];
        self::$issues = [];
        self::$sealed = false;
    }

    private static function tooLate(string $what, string $source): bool
    {
        if (!self::$sealed) {
            return false;
        }
        self::$issues[] = new Issue(Issue::WARNING, 'declared_too_late', sprintf(
            /* translators: 1: rule source or group title, 2: call site */
            __('The redirect "%1$s" was declared at %2$s after redirects were sealed (init, priority 9) and was ignored. Declare redirects in functions.php or before init priority 9.', 'proto-blocks'),
            $what,
            $source
        ), $source, null, GroupFiles::KIND);

        return true;
    }
}
