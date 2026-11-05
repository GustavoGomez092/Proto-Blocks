<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;

/**
 * Every declared script: files (Sources\ScriptFiles) and proto_script()
 * calls (Sources\PhpDeclarations).
 *
 * Same slug in a file and in PHP: the file wins whole and both are reported
 * (`double_declaration` warning, `double` on the script). A broken file also
 * drops the PHP declaration of its slug (fail closed). Built once per
 * instance; invalidate() rebuilds on the next read.
 */
final class Registry
{
    /** @var (\Closure(): ScriptFiles)|null */
    private readonly ?\Closure $files;

    /** @var array{scripts: array<string, array<string, mixed>>, issues: list<Issue>}|null */
    private ?array $built = null;

    /** @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, string>, 3: list<Issue>}|null */
    private ?array $fixed = null;

    /**
     * @param (callable(): ScriptFiles)|null $files default: ScriptFiles over SchemaPaths::roots()
     */
    public function __construct(?callable $files = null)
    {
        $this->files = $files !== null ? \Closure::fromCallable($files) : null;
    }

    /**
     * A registry over fixed scripts, for tests.
     *
     * @param list<array<string, mixed>> $fileScripts
     * @param list<array<string, mixed>> $phpScripts
     * @param array<string, string>      $broken slug => broken file path
     * @param list<Issue>                $issues
     */
    public static function fromArrays(array $fileScripts, array $phpScripts = [], array $broken = [], array $issues = []): self
    {
        $registry = new self();
        $registry->fixed = [$fileScripts, $phpScripts, $broken, $issues];

        return $registry;
    }

    /** @return list<array<string, mixed>> every script, in slug order */
    public function all(): array
    {
        return array_values($this->build()['scripts']);
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        return $this->build()['scripts'][$slug] ?? null;
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return $this->build()['issues'];
    }

    public function invalidate(): void
    {
        $this->built = null;
    }

    /** @return array{scripts: array<string, array<string, mixed>>, issues: list<Issue>} */
    private function build(): array
    {
        if ($this->built !== null) {
            return $this->built;
        }

        if ($this->fixed !== null) {
            [$fileList, $phpList, $broken, $issues] = $this->fixed;
            $files = [];
            foreach ($fileList as $s) {
                $files[(string) $s['slug']] = $s;
            }
            $php = [];
            foreach ($phpList as $s) {
                $php[(string) $s['slug']] = $s;
            }
            $phpSources = array_map(static fn (array $s): string => (string) $s['source'], $php);
        } else {
            $source = $this->files !== null ? ($this->files)() : new ScriptFiles(static fn (): array => SchemaPaths::roots());
            $files = $source->scripts();
            $broken = $source->broken();
            $issues = [...$source->issues(), ...PhpDeclarations::issues()];
            $php = PhpDeclarations::scripts();
            $phpSources = [];
            foreach (PhpDeclarations::slugs() as $slug) {
                $phpSources[$slug] = (string) PhpDeclarations::source($slug);
            }
        }

        $scripts = $files;
        foreach ($phpSources as $slug => $phpSource) {
            $slug = (string) $slug;
            if (isset($files[$slug])) {
                $scripts[$slug]['double'] = ['file' => (string) $files[$slug]['source'], 'php' => $phpSource];
                $issues[] = new Issue(
                    Issue::WARNING,
                    'double_declaration',
                    sprintf(
                        /* translators: 1: script slug, 2: file path, 3: PHP file and line */
                        __('The script "%1$s" is declared in %2$s and in %3$s. The file is used.', 'proto-blocks'),
                        $slug,
                        (string) $files[$slug]['source'],
                        $phpSource
                    ),
                    (string) $files[$slug]['source'],
                    $slug,
                    'script'
                );
                continue;
            }
            if (isset($broken[$slug])) {
                $issues[] = new Issue(
                    Issue::ERROR,
                    'broken_override',
                    sprintf(
                        /* translators: 1: broken file, 2: PHP file and line */
                        __('%1$s is broken; the PHP declaration of the same script (%2$s) was NOT used either.', 'proto-blocks'),
                        $broken[$slug],
                        $phpSource
                    ),
                    $broken[$slug],
                    $slug,
                    'script'
                );
                continue;
            }
            if (isset($php[$slug])) {
                $scripts[$slug] = $php[$slug];
            }
        }
        ksort($scripts, SORT_STRING);

        return $this->built = ['scripts' => $scripts, 'issues' => $issues];
    }
}
