<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\ContentModelCommands;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Guardrails;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Tests\ContentModel\SafeFileTest;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/theme.php';
require_once __DIR__ . '/../ContentModel/SafeFileTest.php';
require_once __DIR__ . '/../../../includes/content-model.php';

/**
 * The testable core of `wp proto-blocks type|taxonomy`: slugs, file creation,
 * the validate report and rename. No WP-CLI runtime is involved.
 */
final class ContentModelCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-cli-cm-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SafeFileTest::removeTree($this->root);
    }

    private function registry(): Registry
    {
        return new Registry(new FileLoader(), fn(): array => [$this->root]);
    }

    private function writer(): SchemaFileWriter
    {
        return new SchemaFileWriter([$this->root], true);
    }

    private function put(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function assertWriteError(string $code, int $status, callable $fn): void
    {
        try {
            $fn();
        } catch (WriteException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);
            return;
        }
        $this->fail("Expected WriteException $code");
    }

    // ---- normalizeSlug -------------------------------------------------------

    public function testNormalizeSlugLowercasesTrimsAndKeepsUnderscores(): void
    {
        $this->assertSame('oit_resource', ContentModelCommands::normalizeSlug(' Oit_Resource ', 'type'));
        $this->assertSame('case-study', ContentModelCommands::normalizeSlug('case-study', 'type'));
    }

    public function testNormalizeSlugRefusesReservedTraversalAndOverlong(): void
    {
        $this->assertNull(ContentModelCommands::normalizeSlug('author', 'type'));
        $this->assertNull(ContentModelCommands::normalizeSlug('../x', 'type'));
        $this->assertNull(ContentModelCommands::normalizeSlug('', 'type'));
        $this->assertNull(ContentModelCommands::normalizeSlug(str_repeat('a', 21), 'type'));
    }

    public function testNormalizeSlugLimitsAreKindSpecific(): void
    {
        $this->assertSame(str_repeat('a', 25), ContentModelCommands::normalizeSlug(str_repeat('a', 25), 'taxonomy'));
        $this->assertNull(ContentModelCommands::normalizeSlug(str_repeat('a', 33), 'taxonomy'));
    }

    // ---- createDeclaration ---------------------------------------------------

    public function testCreateWritesTheCommentedTemplate(): void
    {
        $result = ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());

        $this->assertSame($this->root . '/proto-types/case-study/type.jsonc', $result['path']);
        $text = (string) file_get_contents($result['path']);
        $this->assertStringStartsWith('// Proto-Blocks post type declaration.', $text);
        $this->assertStringContainsString('"name": "Case Study"', $text);
    }

    public function testCreateHonoursSlugAndPluralAndWritesATaxonomy(): void
    {
        $type = ContentModelCommands::createDeclaration('type', 'Resource', 'oit_resource', 'Resource Library', $this->writer(), $this->registry());
        $this->assertSame($this->root . '/proto-types/oit_resource/type.jsonc', $type['path']);
        $this->assertStringContainsString("\n  \"plural\": \"Resource Library\",", (string) file_get_contents($type['path']));

        $tax = ContentModelCommands::createDeclaration('taxonomy', 'Topic', null, null, $this->writer(), $this->registry());
        $this->assertSame($this->root . '/proto-taxonomies/topic/taxonomy.jsonc', $tax['path']);
        $this->assertStringStartsWith('// Proto-Blocks taxonomy declaration.', (string) file_get_contents($tax['path']));
    }

    public function testCreateRefusesAnExistingSlug(): void
    {
        ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());

        $this->assertWriteError('exists', 409, fn() => ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry()));
    }

    public function testCreateRefusesAReservedSlug(): void
    {
        $this->assertWriteError('invalid_slug', 400, fn() => ContentModelCommands::createDeclaration('type', 'Author', null, null, $this->writer(), $this->registry()));
        $this->assertWriteError('invalid_slug', 400, fn() => ContentModelCommands::createDeclaration('type', 'Thing', '../x', null, $this->writer(), $this->registry()));
        $this->assertFileDoesNotExist($this->root . '/proto-types/author/type.jsonc');
    }

    // ---- validationRows ------------------------------------------------------

    public function testValidationRowsReportADoubleAndABrokenFile(): void
    {
        proto_post_type('Product');
        $this->put('proto-types/product/type.jsonc', '{"name": "Product"}');
        $this->put('proto-types/broken/type.jsonc', '{"name": ');

        $registry = $this->registry();
        // The modules hand back the Registry's issues too: they must not repeat.
        $rows = ContentModelCommands::validationRows($registry, $registry->issues());

        $this->assertCount(2, $rows);
        $byCode = array_column($rows, null, 'code');
        $this->assertSame('warning', $byCode['double_declaration']['level']);
        $this->assertSame('product', $byCode['double_declaration']['slug']);
        $this->assertSame('type', $byCode['double_declaration']['kind']);
        $this->assertSame('proto-types/product/type.jsonc', $byCode['double_declaration']['source']);
        $this->assertStringContainsString('ContentModelCommandsTest.php', $byCode['double_declaration']['message']);
        $this->assertSame('error', $byCode['invalid_jsonc']['level']);
        $this->assertSame('proto-types/broken/type.jsonc', $byCode['invalid_jsonc']['source']);
    }

    // ---- list ----------------------------------------------------------------

    public function testListRowsShowOriginDoubleAndCount(): void
    {
        proto_post_type('Product');
        $this->put('proto-types/product/type.jsonc', '{"name": "Product"}');
        ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());

        $guardrails = new Guardrails(fn(string $t): int => $t === 'case-study' ? 3 : 0, fn(string $t): int => 0);
        $rows = array_column(ContentModelCommands::listRows('type', $this->registry(), $guardrails), null, 'slug');

        $this->assertSame('file', $rows['case-study']['origin']);
        $this->assertSame('proto-types/case-study/type.jsonc', $rows['case-study']['source']);
        $this->assertSame('no', $rows['case-study']['double']);
        $this->assertSame(3, $rows['case-study']['posts']);
        $this->assertSame('yes', $rows['product']['double']);
    }

    // ---- rename --------------------------------------------------------------

    public function testRenameProblemRefusesPhpTakenAndSameSlugs(): void
    {
        proto_post_type('Gadget');
        ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());
        ContentModelCommands::createDeclaration('type', 'Product', null, null, $this->writer(), $this->registry());
        $registry = $this->registry();

        $problem = fn(string $old, string $new) => ContentModelCommands::renameProblem('type', $old, $new, $registry->get('type', $old), $registry);

        $this->assertNull($problem('case-study', 'study'));
        $this->assertNotNull($problem('gadget', 'widget'));
        $this->assertNotNull($problem('case-study', 'product'));
        $this->assertNotNull($problem('case-study', 'case-study'));
        $this->assertNotNull($problem('missing', 'other'));
    }

    public function testMoveDeclarationMovesTheFileToTheNewFolder(): void
    {
        ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());
        $existing = $this->registry()->get('type', 'case-study');
        $this->assertNotNull($existing);

        $moved = ContentModelCommands::moveDeclaration('type', 'case-study', 'study', $existing, $this->writer());

        $this->assertSame($this->root . '/proto-types/study/type.jsonc', $moved['path']);
        $this->assertSame([], $moved['kept']);
        $this->assertFileExists($moved['path']);
        $this->assertDirectoryDoesNotExist($this->root . '/proto-types/case-study');
        $this->assertNotNull($this->registry()->get('type', 'study'));
    }
}
