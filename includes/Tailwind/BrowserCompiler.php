<?php
/**
 * Browser Compiler - Receives browser-compiled Tailwind CSS and stores it.
 *
 * The shell-less counterpart to the CLI compile: the browser produces raw CSS,
 * this scopes it (reusing Scoper) and saves it (reusing Cache). No exec.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

class BrowserCompiler
{
    private Scoper $scoper;
    private Cache $cache;

    public function __construct(Scoper $scoper, Cache $cache)
    {
        $this->scoper = $scoper;
        $this->cache = $cache;
    }

    /**
     * Scope and persist browser-produced CSS.
     *
     * @return bool True when written; false when the input is empty or the
     *              write fails.
     */
    public function store(string $rawCss): bool
    {
        if (trim($rawCss) === '') {
            return false;
        }

        $scoped = $this->scoper->scopeCompiledCss($rawCss);

        return $this->cache->saveContent($scoped);
    }
}
