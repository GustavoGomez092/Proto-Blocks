<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts\Definition;

use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;
use ProtoBlocks\Tests\Modules\Scripts\ScriptsTestCase;

/**
 * Every validator code, plus the normaliser's defaults.
 */
final class ScriptValidatorTest extends ScriptsTestCase
{
    /** @return list<string> */
    private static function codes(array $data, bool $php = false, string $level = 'errors'): array
    {
        return array_column(ScriptValidator::validate($data, $php)[$level], 'code');
    }

    /** @return list<string> */
    private static function paths(array $data): array
    {
        return array_column(ScriptValidator::validate($data)['errors'], 'path');
    }

    public function testSpecExampleIsValid(): void
    {
        $data = [
            'title' => 'Google Tag Manager',
            'enabled' => true,
            'priority' => 10,
            'head' => ['file' => 'head.html'],
            'bodyOpen' => ['file' => 'body-open.html'],
            'bodyClose' => '',
            'where' => [
                'include' => ['all'],
                'exclude' => ['page:privacy-policy', 'url:/landing/*'],
                'loggedIn' => null,
                'roles' => [],
                'excludeRoles' => ['administrator'],
            ],
        ];
        self::assertSame(['errors' => [], 'warnings' => []], ScriptValidator::validate($data));
    }

    public function testTitleRules(): void
    {
        self::assertSame(['/title'], self::paths([]));
        self::assertSame(['/title'], self::paths(['title' => '']));
        self::assertSame(['/title'], self::paths(['title' => 'A <b>tag</b>']));
        self::assertSame(['/title'], self::paths(['title' => str_repeat('a', 101)]));
        self::assertSame([], self::paths(['title' => 'Plain title']));
    }

    public function testUnknownKeysAndUntrustedKeysAreRefused(): void
    {
        self::assertSame(['/colour'], self::paths(['title' => 'x', 'colour' => 'red']));
        self::assertSame(['/register_meta_box_cb'], self::paths(['title' => 'x', 'register_meta_box_cb' => 'evil']));
        self::assertSame(['/where/everywhere'], self::paths(['title' => 'x', 'where' => ['everywhere' => true]]));
    }

    public function testPriorityRules(): void
    {
        self::assertSame(['/priority'], self::paths(['title' => 'x', 'priority' => 1.5]));
        self::assertSame(['/priority'], self::paths(['title' => 'x', 'priority' => '10']));
        self::assertSame(['/priority'], self::paths(['title' => 'x', 'priority' => 100001]));
        self::assertSame([], self::paths(['title' => 'x', 'priority' => -5, 'priorities' => ['head' => 1, 'bodyClose' => 99]]));
        self::assertSame(['/priorities/footer'], self::paths(['title' => 'x', 'priorities' => ['footer' => 1]]));
    }

    public function testUnknownTargetIsSchemaInvalidWithReadableMessage(): void
    {
        $result = ScriptValidator::validate(['title' => 'x', 'where' => ['include' => ['everywhere']]]);
        self::assertSame(['schema_invalid'], array_column($result['errors'], 'code'));
        self::assertSame('/where/include/0', $result['errors'][0]['path']);
        self::assertStringContainsString('"everywhere" is not a placement target', $result['errors'][0]['message']);
    }

    public function testListCaps(): void
    {
        $many = array_fill(0, 101, 'all');
        self::assertSame(['/where/include'], self::paths(['title' => 'x', 'where' => ['include' => $many]]));
        self::assertContains('/where/exclude/0', self::paths(['title' => 'x', 'where' => ['exclude' => ['url:/' . str_repeat('a', 200)]]]));
        self::assertSame(['/where/roles/1'], self::paths(['title' => 'x', 'where' => ['roles' => ['editor', 'editor']]]));
        self::assertSame(['/where/roles/0'], self::paths(['title' => 'x', 'where' => ['roles' => ['bad role']]]));
    }

    public function testInvalidSnippetFile(): void
    {
        self::assertSame(['invalid_snippet_file'], self::codes(['title' => 'x', 'head' => ['file' => '../wp-config.php']]));
        self::assertSame(['invalid_snippet_file'], self::codes(['title' => 'x', 'head' => ['file' => 'head.js']]));
        self::assertSame(['invalid_snippet_file'], self::codes(['title' => 'x', 'head' => ['file' => 'HEAD.html']]));
        self::assertSame([], self::codes(['title' => 'x', 'head' => ['file' => 'body-close.html']]), 'any of the three names');
        self::assertSame(['schema_invalid'], array_values(array_unique(self::codes(['title' => 'x', 'head' => ['path' => 'head.html']]))));
        self::assertSame(['schema_invalid'], self::codes(['title' => 'x', 'head' => 42]));
    }

    public function testPhpDeclarationsTakeStringsOnly(): void
    {
        self::assertSame(['invalid_snippet_file'], self::codes(['title' => 'x', 'head' => ['file' => 'head.html']], true));
        self::assertSame([], self::codes(['title' => 'x', 'head' => '<meta name="x" content="y">'], true));
    }

    public function testPhpInSnippet(): void
    {
        foreach (['<?php echo 1; ?>', '<?PHP x', '<?= $x ?>', "<? echo 1;", "<?\nfoo", "<script>// <?php</script>"] as $bad) {
            self::assertSame(['php_in_snippet'], self::codes(['title' => 'x', 'bodyClose' => $bad]), $bad);
        }
        foreach (['<?xml version="1.0"?>', '<script>if (a<b) {}</script>', 'a ? b : c', '<!-- ? -->'] as $ok) {
            self::assertSame([], self::codes(['title' => 'x', 'bodyClose' => $ok]), $ok);
        }
    }

    public function testSnippetTooLarge(): void
    {
        self::assertSame('snippet_too_large', ScriptValidator::snippetError(str_repeat('a', 262145), '/head')['code'] ?? null);
        self::assertNull(ScriptValidator::snippetError(str_repeat('a', 262144), '/head'));
    }

    public function testConflictingWhere(): void
    {
        self::assertSame(['conflicting_where'], self::codes(['title' => 'x', 'where' => ['loggedIn' => false, 'roles' => ['editor']]]));
        self::assertSame([], self::codes(['title' => 'x', 'where' => ['loggedIn' => true, 'roles' => ['editor']]]));
    }

    public function testNeverMatchesWarning(): void
    {
        self::assertSame(['never_matches'], self::codes(['title' => 'x', 'where' => ['include' => []]], false, 'warnings'));
        self::assertSame([], self::codes(['title' => 'x', 'where' => []], false, 'warnings'));
    }

    public function testNormalizerDefaults(): void
    {
        $s = ScriptNormalizer::normalize(['title' => 'GTM'], 'gtm', 'file', '/x/script.jsonc', '/x');
        self::assertTrue($s['enabled']);
        self::assertSame(10, $s['priority']);
        self::assertSame(['head' => 10, 'bodyOpen' => 10, 'bodyClose' => 10], $s['priorities']);
        self::assertSame(['mode' => null, 'file' => null, 'code' => ''], $s['locations']['head']);
        self::assertSame(['all'], $s['where']['include']);
        self::assertSame([], ScriptNormalizer::present($s));
    }

    public function testNormalizerInlineFileAndOverrides(): void
    {
        $s = ScriptNormalizer::normalize(
            ['title' => 'GTM', 'enabled' => false, 'priority' => 5, 'priorities' => ['bodyClose' => 99], 'head' => '<meta>', 'bodyClose' => ['file' => 'body-close.html'], 'bodyOpen' => ['file' => 'body-open.html']],
            'gtm',
            'file',
            '/x/script.jsonc',
            '/x',
            ['body-close.html' => '<script>1</script>']
        );
        self::assertFalse($s['enabled']);
        self::assertSame(['head' => 5, 'bodyOpen' => 5, 'bodyClose' => 99], $s['priorities']);
        self::assertSame(['mode' => 'inline', 'file' => null, 'code' => '<meta>'], $s['locations']['head']);
        self::assertSame(['mode' => 'file', 'file' => 'body-close.html', 'code' => '<script>1</script>'], $s['locations']['bodyClose']);
        self::assertSame('', $s['locations']['bodyOpen']['code'], 'a missing file outputs nothing');
        self::assertSame(['head', 'bodyClose'], ScriptNormalizer::present($s));
    }
}
