<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;

require_once __DIR__ . '/../../../../includes/Modules/Scripts/functions.php';

/**
 * proto_script(), sealing, and the file-wins-whole registry.
 */
final class RegistryTest extends ScriptsTestCase
{
    private function registry(): Registry
    {
        return new Registry(fn (): ScriptFiles => new ScriptFiles(fn (): array => [$this->root]));
    }

    /** @return list<string> */
    private static function codes(Registry $r): array
    {
        return array_map(static fn (Issue $i): string => $i->code, $r->issues());
    }

    public function testPhpDeclaration(): void
    {
        $line = __LINE__ + 1;
        self::assertSame('google-tag-manager', proto_script('Google Tag Manager', ['head' => '<script>gtm()</script>', 'priority' => 3]));

        $s = $this->registry()->get('google-tag-manager');
        self::assertNotNull($s);
        self::assertSame('php', $s['origin']);
        self::assertSame(__FILE__ . ':' . $line, $s['source']);
        self::assertSame('<script>gtm()</script>', $s['locations']['head']['code']);
        self::assertSame(3, $s['priorities']['head']);
        self::assertNull($s['double']);
    }

    public function testExplicitSlugAndInvalidSlug(): void
    {
        self::assertSame('ga4', proto_script('Analytics', ['slug' => 'ga4']));
        self::assertNull(proto_script('Analytics', ['slug' => 'Bad Slug']));
        self::assertNull(proto_script('123'));
        $r = $this->registry();
        self::assertSame(['ga4'], array_column($r->all(), 'slug'));
        self::assertSame(['invalid_slug', 'invalid_slug'], self::codes($r));
    }

    public function testPhpFileFormIsRefused(): void
    {
        proto_script('GTM', ['head' => ['file' => 'head.html']]);
        $r = $this->registry();
        self::assertSame([], $r->all());
        self::assertSame(['invalid_snippet_file'], self::codes($r));
    }

    public function testPhpInPhpSnippetIsRefused(): void
    {
        proto_script('GTM', ['bodyClose' => '<?= 1 ?>']);
        $r = $this->registry();
        self::assertSame([], $r->all());
        self::assertSame(['php_in_snippet'], self::codes($r));
    }

    public function testSealedAfterTemplateRedirect(): void
    {
        proto_script('Early', ['head' => '<meta>']);
        PhpDeclarations::seal();
        self::assertNull(proto_script('Late', ['head' => '<meta>']));
        $r = $this->registry();
        self::assertSame(['early'], array_column($r->all(), 'slug'));
        self::assertSame(['declared_too_late'], self::codes($r));
        self::assertSame(Issue::WARNING, $r->issues()[0]->level);
    }

    public function testRedeclaringInPhpReplacesWithAWarning(): void
    {
        proto_script('GTM', ['head' => '<a>']);
        proto_script('GTM', ['head' => '<b>']);
        $r = $this->registry();
        self::assertSame('<b>', $r->get('gtm')['locations']['head']['code']);
        self::assertSame(['duplicate_php_declaration'], self::codes($r));
    }

    public function testFileWinsWholeOverPhp(): void
    {
        proto_script('GTM', ['head' => '<php-head>', 'bodyClose' => '<php-close>']);
        $path = $this->script('gtm', ['title' => 'GTM file', 'head' => '<file-head>']);

        $r = $this->registry();
        $s = $r->get('gtm');
        self::assertSame('file', $s['origin']);
        self::assertSame('<file-head>', $s['locations']['head']['code']);
        self::assertSame('', $s['locations']['bodyClose']['code'], 'nothing is merged from PHP');
        self::assertSame($path, $s['double']['file']);
        self::assertStringEndsWith('RegistryTest.php:' . (__LINE__ - 9), $s['double']['php']);
        self::assertSame(['double_declaration'], self::codes($r));
    }

    public function testBrokenFileDropsThePhpDeclarationToo(): void
    {
        proto_script('GTM', ['head' => '<php-head>']);
        $this->put('proto-scripts/gtm/script.jsonc', '{ nope');
        $r = $this->registry();
        self::assertNull($r->get('gtm'));
        self::assertSame(['invalid_jsonc', 'broken_override'], self::codes($r));
    }

    public function testAllIsSortedBySlug(): void
    {
        proto_script('Zed');
        $this->script('alpha', ['title' => 'Alpha']);
        proto_script('Mid');
        self::assertSame(['alpha', 'mid', 'zed'], array_column($this->registry()->all(), 'slug'));
    }

    public function testInvalidateRebuilds(): void
    {
        $r = $this->registry();
        self::assertSame([], $r->all());
        $this->script('gtm', ['title' => 'GTM']);
        self::assertSame([], $r->all(), 'cached for the request');
        $r->invalidate();
        self::assertSame(['gtm'], array_column($r->all(), 'slug'));
    }

    public function testFromArrays(): void
    {
        $r = Registry::fromArrays(
            [['slug' => 'b', 'source' => '/f/b/script.jsonc']],
            [['slug' => 'b', 'source' => 'functions.php:3'], ['slug' => 'a', 'source' => 'functions.php:4']]
        );
        self::assertSame(['a', 'b'], array_column($r->all(), 'slug'));
        self::assertSame(['file' => '/f/b/script.jsonc', 'php' => 'functions.php:3'], $r->get('b')['double']);
    }
}
