<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\Modules\ModuleInterface;

/**
 * The redirects module. Skeleton: the design is docs/superpowers/specs/2026-11-05-redirects-design.md;
 * the implementation fills in boot() and the classes under this namespace.
 */
final class RedirectsModule implements ModuleInterface
{
    public const ID = 'redirects';

    private static ?self $instance = null;

    public function __construct(private readonly Registry $registry)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function boot(): void
    {
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return [];
    }

    /** @internal Set by Modules\Manager when the module boots. */
    public static function setInstance(?self $module): void
    {
        self::$instance = $module;
    }

    /** Null when the module is disabled or not booted yet. */
    public static function instance(): ?self
    {
        return self::$instance;
    }
}
