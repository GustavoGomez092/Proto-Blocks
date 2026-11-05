<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts\Sources;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;
use ProtoBlocks\Tests\Modules\Scripts\ScriptsTestCase;

/**
 * The file loader: discovery, child-theme precedence, snippet files,
 * confinement and every loader code.
 */
final class ScriptFilesTest extends ScriptsTestCase
{
    private function files(string ...$roots): ScriptFiles
    {
        $roots = $roots === [] ? [$this->root] : $roots;

        return new ScriptFiles(static fn (): array => $roots);
    }

    /** @return list<string> */
    private static function codes(ScriptFiles $files, ?string $level = null): array
    {
        return array_values(array_map(
            static fn (Issue $i): string => $i->code,
            array_filter($files->issues(), static fn (Issue $i): bool => $level === null || $i->level === $level)
        ));
    }

    public function testLoadsFileAndInlineSnippets(): void
    {
        $this->script('gtm', [
            'title' => 'GTM',
            'head' => ['file' => 'head.html'],
            'bodyOpen' => ['file' => 'body-open.html'],
            'bodyClose' => '<script>inline()</script>',
        ], ['head.html' => '<script>gtm()</script>', 'body-open.html' => '<noscript>x</noscript>']);

        $scripts = $this->files()->scripts();
        self::assertSame(['gtm'], array_keys($scripts));
        $s = $scripts['gtm'];
        self::assertSame('file', $s['origin']);
        self::assertSame($this->root . '/proto-scripts/gtm/script.jsonc', $s['source']);
        self::assertSame('<script>gtm()</script>', $s['locations']['head']['code']);
        self::assertSame('<noscript>x</noscript>', $s['locations']['bodyOpen']['code']);
        self::assertSame(['mode' => 'inline', 'file' => null, 'code' => '<script>inline()</script>'], $s['locations']['bodyClose']);
    }

    public function testJsoncCommentsAndJsonFallback(): void
    {
        $this->put('proto-scripts/a/script.jsonc', "// comment\n{ \"title\": \"A\", /* x */ \"head\": \"<meta>\", }\n");
        $this->put('proto-scripts/b/script.json', '{"title": "B"}');
        self::assertSame(['a', 'b'], array_keys($this->files()->scripts()));
    }

    public function testBothExtensionsWarns(): void
    {
        $this->put('proto-scripts/a/script.jsonc', '{"title": "From jsonc"}');
        $this->put('proto-scripts/a/script.json', '{"title": "From json"}');
        $files = $this->files();
        self::assertSame('From jsonc', $files->scripts()['a']['title']);
        self::assertSame(['both_extensions'], self::codes($files));
    }

    public function testMissingSnippetFileWarnsAndOutputsNothing(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'head' => ['file' => 'head.html']]);
        $files = $this->files();
        self::assertSame('', $files->scripts()['gtm']['locations']['head']['code']);
        self::assertSame(['snippet_file_missing'], self::codes($files, Issue::WARNING));
    }

    public function testPhpInSnippetFileSkipsTheScript(): void
    {
        $this->script('bad', ['title' => 'Bad', 'head' => ['file' => 'head.html']], ['head.html' => "<?php system('id'); ?>"]);
        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame(['php_in_snippet'], self::codes($files, Issue::ERROR));
        self::assertArrayHasKey('bad', $files->broken());
    }

    public function testInvalidSnippetFileNameSkipsTheScript(): void
    {
        $this->script('bad', ['title' => 'Bad', 'head' => ['file' => '../../functions.php']]);
        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame(['invalid_snippet_file'], self::codes($files, Issue::ERROR));
    }

    public function testSymlinkedSnippetIsRefused(): void
    {
        $outside = $this->mkRoot('pb-scripts-out-');
        file_put_contents($outside . '/secret.html', 'SECRET');
        $this->script('gtm', ['title' => 'GTM', 'head' => ['file' => 'head.html']]);
        symlink($outside . '/secret.html', $this->root . '/proto-scripts/gtm/head.html');

        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame(['path_outside_root'], self::codes($files, Issue::ERROR));
    }

    public function testSymlinkedScriptFolderIsRefused(): void
    {
        $outside = $this->mkRoot('pb-scripts-out-');
        mkdir($outside . '/evil');
        file_put_contents($outside . '/evil/script.jsonc', '{"title":"Evil","head":"<script>x</script>"}');
        mkdir($this->root . '/proto-scripts');
        symlink($outside . '/evil', $this->root . '/proto-scripts/evil');

        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame(['path_outside_root'], self::codes($files));
    }

    public function testOversizedFilesAreNotRead(): void
    {
        $this->put('proto-scripts/big/script.jsonc', '{"title":"Big","x":"' . str_repeat('a', 262200) . '"}');
        $this->script('bigsnip', ['title' => 'Big snippet', 'head' => ['file' => 'head.html']], ['head.html' => str_repeat('a', 262145)]);
        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame(['file_too_large', 'file_too_large'], self::codes($files));
    }

    public function testInvalidJsoncAndSchemaErrors(): void
    {
        $this->put('proto-scripts/broken/script.jsonc', '{ "title": ');
        $this->script('noschema', ['title' => 'x', 'where' => ['include' => ['nowhere-known']]]);
        $this->put('proto-scripts/Upper/script.jsonc', '{"title":"x"}');
        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertEqualsCanonicalizing(['invalid_jsonc', 'schema_invalid', 'invalid_slug'], self::codes($files));
    }

    public function testUnsafeKeysAreStrippedWithAWarning(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'register_meta_box_cb' => 'evil']);
        $files = $this->files();
        self::assertArrayHasKey('gtm', $files->scripts());
        self::assertSame(['unsafe_key'], self::codes($files));
    }

    public function testChildThemeWinsOnTheSameSlug(): void
    {
        $child = $this->mkRoot('pb-scripts-child-');
        $this->script('gtm', ['title' => 'Parent']);
        $this->script('only-parent', ['title' => 'Only parent']);
        $this->script('gtm', ['title' => 'Child'], [], $child);

        $scripts = $this->files($this->root, $child)->scripts();
        self::assertSame(['gtm', 'only-parent'], array_keys($scripts));
        self::assertSame('Child', $scripts['gtm']['title']);
        self::assertSame($child, $scripts['gtm']['root']);
    }

    public function testBrokenChildDropsTheParentFile(): void
    {
        $child = $this->mkRoot('pb-scripts-child-');
        $this->script('gtm', ['title' => 'Parent', 'head' => '<meta>']);
        $this->put('proto-scripts/gtm/script.jsonc', '{ broken', $child);

        $files = $this->files($this->root, $child);
        self::assertSame([], $files->scripts());
        self::assertContains('broken_override', self::codes($files));
        self::assertSame(['gtm'], array_keys($files->broken()));
    }

    public function testFolderWithoutScriptFileIsIgnored(): void
    {
        $this->put('proto-scripts/empty/readme.txt', 'x');
        $files = $this->files();
        self::assertSame([], $files->scripts());
        self::assertSame([], $files->issues());
    }

    public function testNeverMatchesWarningIsReported(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'where' => ['include' => []]]);
        self::assertSame(['never_matches'], self::codes($this->files()));
    }
}
