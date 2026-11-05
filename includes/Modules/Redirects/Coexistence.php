<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

/**
 * While the Redirection plugin is active the module stays passive (no
 * redirects, no logging) unless the settings say `coexist: true`, so a
 * request is never redirected twice during a migration.
 */
final class Coexistence
{
    /** @var (callable(): bool)|null tests only: replaces redirectionActive() for every caller */
    private static $detector = null;

    /**
     * @param array<string, mixed>   $settings fully defaulted settings
     * @param (callable(): bool)|null $detect   tests only: replaces redirectionActive()
     */
    public static function paused(array $settings, ?callable $detect = null): bool
    {
        if (($settings['coexist'] ?? false) === true) {
            return false;
        }
        $detect ??= self::$detector;

        return (bool) ($detect !== null ? $detect() : self::redirectionActive());
    }

    /** The Redirection plugin is loaded on this request. */
    public static function redirectionActive(): bool
    {
        return class_exists('Redirection_Admin', false) || defined('REDIRECTION_VERSION');
    }

    /**
     * @internal Tests only (a real Redirection_Admin class or constant would
     * leak into every later test): null restores the real detection.
     *
     * @param (callable(): bool)|null $detect
     */
    public static function setDetector(?callable $detect): void
    {
        self::$detector = $detect;
    }
}
