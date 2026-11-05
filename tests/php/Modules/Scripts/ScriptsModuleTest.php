<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Scripts\Output;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;

require_once __DIR__ . '/../../../../includes/Modules/Scripts/functions.php';

/**
 * ScriptsModule::boot() wiring, issues() and the write reason.
 */
final class ScriptsModuleTest extends ScriptsTestCase
{
    private function module(): ScriptsModule
    {
        $root = $this->root;

        return new ScriptsModule(
            new ContentModelRegistry(new FileLoader(), static fn (): array => [$root]),
            new Registry(static fn (): ScriptFiles => new ScriptFiles(static fn (): array => [$root]))
        );
    }

    /** @return list<mixed> */
    private static function callbacks(string $hook): array
    {
        return array_column($GLOBALS['pb_test_hooks'][$hook] ?? [], 'callback');
    }

    public function testBootWiresEverything(): void
    {
        $module = $this->module();
        $module->boot();
        $module->boot();

        self::assertSame('scripts', $module->id());
        self::assertContains('script', SchemaFileWriter::extendedKinds());
        self::assertSame([[PhpDeclarations::class, 'seal']], self::callbacks('template_redirect'));
        self::assertSame(1, $GLOBALS['pb_test_hooks']['template_redirect'][0]['priority']);
        foreach (['wp_head', 'wp_body_open', 'wp_footer'] as $hook) {
            self::assertCount(1, self::callbacks($hook), $hook . ' hooked once');
            self::assertSame(1, $GLOBALS['pb_test_hooks'][$hook][0]['priority']);
        }
        self::assertSame([[$module, 'guardSchemaFiles']], self::callbacks('rest_request_before_callbacks'));
        self::assertSame([[$module, 'afterSchemaFiles']], self::callbacks('rest_request_after_callbacks'));
    }

    public function testBootTouchesNoFile(): void
    {
        $calls = 0;
        $module = new ScriptsModule(
            new ContentModelRegistry(new FileLoader(), static fn (): array => []),
            new Registry(static function () use (&$calls): ScriptFiles {
                $calls++;
                return new ScriptFiles(static fn (): array => []);
            })
        );
        $module->boot();
        self::assertSame(0, $calls);
    }

    public function testEndToEndHeadOutput(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'head' => ['file' => 'head.html']], ['head.html' => '<script>gtm()</script>']);
        proto_script('Verify', ['head' => '<meta name="v" content="1">', 'priority' => 1]);
        $module = $this->module();
        $module->boot();

        ob_start();
        $module->output()->printHead();
        $html = (string) ob_get_clean();
        self::assertSame("\n<!-- proto-blocks: verify -->\n<meta name=\"v\" content=\"1\">\n\n<!-- proto-blocks: gtm -->\n<script>gtm()</script>\n", $html);
    }

    public function testIssuesIncludeRegistryAndBodyOpen(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'bodyOpen' => '<noscript></noscript>', 'head' => ['file' => 'head.html']]);
        $GLOBALS['pb_test_options'][Output::BODY_OPEN_OPTION] = [basename($this->root) => true];
        $codes = array_map(static fn (Issue $i): string => $i->code, $this->module()->issues());
        self::assertSame(['snippet_file_missing', 'missing_body_open'], $codes);
    }

    public function testWriteReason(): void
    {
        $this->loginAs(1, ['administrator'], ['manage_options']);
        self::assertSame('unfiltered_html', ScriptsModule::writeReason());
        $this->loginAs(1, ['administrator'], ['manage_options', 'unfiltered_html']);
        self::assertNull(ScriptsModule::writeReason());
        $GLOBALS['pb_test_multisite'] = true;
        self::assertSame('multisite', ScriptsModule::writeReason(), 'inherited reasons come first');
        $GLOBALS['pb_test_multisite'] = false;
    }

    public function testInstance(): void
    {
        self::assertNull(ScriptsModule::instance());
        $module = $this->module();
        ScriptsModule::setInstance($module);
        self::assertSame($module, ScriptsModule::instance());
    }
}
