<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules;

/**
 * One switchable part of the plugin. boot() registers only the module's own
 * hooks; a module never calls into another except through declared
 * interfaces.
 */
interface ModuleInterface
{
    /** Key in the `proto_blocks_modules` filter, e.g. 'post_types'. */
    public function id(): string;

    public function boot(): void;
}
