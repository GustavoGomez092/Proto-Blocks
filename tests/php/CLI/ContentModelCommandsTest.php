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
require_once __DIR__ . '/wp-cli-stub.php';
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
        \WP_CLI::reset();
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

    // ---- runRename (stub WP_CLI, fake $wpdb) ------------------------------------

    private function fakeWpdb(int|false $result): object
    {
        return new class ($result) {
            public string $posts = 'wp_posts';
            public string $term_taxonomy = 'wp_term_taxonomy';
            /** @var list<array> */
            public array $updates = [];

            public function __construct(private int|false $result)
            {
            }

            public function update(string $table, array $data, array $where): int|false
            {
                $this->updates[] = [$table, $data, $where];
                return $this->result;
            }
        };
    }

    /** @param array<string, int> $counts slug => existing content */
    private function rename(array $assoc, object $wpdb, array $counts = ['case-study' => 2]): ?string
    {
        $guardrails = new Guardrails(fn(string $t): int => $counts[$t] ?? 0, fn(string $t): int => $counts[$t] ?? 0);
        try {
            ContentModelCommands::runRename('type', 'case-study', 'study', $assoc, $this->registry(), $this->writer(), $guardrails, $wpdb);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function seedCaseStudy(): void
    {
        ContentModelCommands::createDeclaration('type', 'Case Study', null, null, $this->writer(), $this->registry());
    }

    public function testRenameWithoutYesPrintsThePlanAndExitsNonZero(): void
    {
        $this->seedCaseStudy();
        $wpdb = $this->fakeWpdb(2);

        $error = $this->rename([], $wpdb);

        $this->assertNotNull($error);
        $this->assertStringContainsString('2 posts affected', implode("\n", \WP_CLI::messages('log')));
        $this->assertFileExists($this->root . '/proto-types/case-study/type.jsonc');
        $this->assertFileDoesNotExist($this->root . '/proto-types/study/type.jsonc');
        $this->assertSame([], $wpdb->updates);
    }

    public function testRenameWithYesLeavesContentAndSaysSo(): void
    {
        $this->seedCaseStudy();
        $wpdb = $this->fakeWpdb(2);

        $this->assertNull($this->rename(['yes' => true], $wpdb));

        $this->assertFileExists($this->root . '/proto-types/study/type.jsonc');
        $this->assertSame([], $wpdb->updates);
        $this->assertStringContainsString('stay under the old slug "case-study"', implode("\n", \WP_CLI::messages('warning')));
    }

    public function testRenameWithMigrateUpdatesPostsAndDefersTheFlush(): void
    {
        $this->seedCaseStudy();
        $wpdb = $this->fakeWpdb(2);

        $this->assertNull($this->rename(['yes' => true, 'migrate' => true], $wpdb));

        $this->assertSame([['wp_posts', ['post_type' => 'study'], ['post_type' => 'case-study']]], $wpdb->updates);
        $this->assertStringContainsString('Rewrite rules will refresh on the next page load.', implode("\n", \WP_CLI::messages('log')));
        $this->assertSame([], \WP_CLI::messages('error'));
    }

    public function testRenameMigrateDatabaseFailureErrorsWithTheLeftBehindState(): void
    {
        $this->seedCaseStudy();

        $error = $this->rename(['yes' => true, 'migrate' => true], $this->fakeWpdb(false));

        $this->assertNotNull($error);
        $this->assertStringContainsString('already renamed to "study"', $error);
        $this->assertStringContainsString('still stored under "case-study"', $error);
        $this->assertSame([], \WP_CLI::messages('success'));
    }

    public function testRenameMigrateRefusesWhenContentExistsUnderTheNewSlug(): void
    {
        $this->seedCaseStudy();
        $wpdb = $this->fakeWpdb(2);

        $error = $this->rename(['yes' => true, 'migrate' => true], $wpdb, ['case-study' => 2, 'study' => 1]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('already exist under "study"', $error);
        $this->assertFileExists($this->root . '/proto-types/case-study/type.jsonc');
        $this->assertFileDoesNotExist($this->root . '/proto-types/study/type.jsonc');
        $this->assertSame([], $wpdb->updates);
    }

    public function testMigrateContentUsesTheTaxonomyTable(): void
    {
        $wpdb = $this->fakeWpdb(3);

        $this->assertSame(3, ContentModelCommands::migrateContent('taxonomy', 'topic', 'subject', $wpdb));
        $this->assertSame([['wp_term_taxonomy', ['taxonomy' => 'subject'], ['taxonomy' => 'topic']]], $wpdb->updates);
        $this->assertNull(ContentModelCommands::migrateContent('taxonomy', 'topic', 'subject', $this->fakeWpdb(false)));
    }

    public function testMoveDeclarationRollsBackWhenTheOldFileCannotBeRemoved(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not bind root.');
        }
        $this->seedCaseStudy();
        $existing = $this->registry()->get('type', 'case-study');
        $this->assertNotNull($existing);
        $oldDir = $this->root . '/proto-types/case-study';
        chmod($oldDir, 0555);

        try {
            $thrown = null;
            try {
                ContentModelCommands::moveDeclaration('type', 'case-study', 'study', $existing, $this->writer());
            } catch (WriteException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown);
        } finally {
            chmod($oldDir, 0755);
        }

        $this->assertFileDoesNotExist($this->root . '/proto-types/study/type.jsonc');
        $this->assertDirectoryDoesNotExist($this->root . '/proto-types/study');
        $this->assertFileExists($oldDir . '/type.jsonc');
        $this->assertNull($this->registry()->get('type', 'study'));
    }

    // ---- write gate (same as the REST routes) -------------------------------

    /** The writer the commands build for themselves. */
    private function commandWriter(): SchemaFileWriter
    {
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root];

        return (new \ReflectionMethod(ContentModelCommands::class, 'writer'))->invoke(new ContentModelCommands());
    }

    public function testCommandWriterOnMultisiteNeedsSuperAdmin(): void
    {
        if (defined('DISALLOW_FILE_MODS') || defined('DISALLOW_FILE_EDIT')) {
            $this->markTestSkipped('File mods are disabled in this process.');
        }
        try {
            $this->assertNull($this->commandWriter()->target('type', 'book', null)['reason']);

            $GLOBALS['pb_test_multisite'] = true;
            $GLOBALS['pb_test_current_user'] = 0;
            $this->assertSame('file_mods', $this->commandWriter()->target('type', 'book', null)['reason']);

            // `wp --user=<super admin>`.
            $GLOBALS['pb_test_current_user'] = 7;
            $GLOBALS['pb_test_super_admins'] = [7];
            $this->assertNull($this->commandWriter()->target('type', 'book', null)['reason']);
        } finally {
            unset($GLOBALS['pb_test_multisite'], $GLOBALS['pb_test_super_admins'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_theme']);
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCommandWriterHonoursDisallowFileEdit(): void
    {
        define('DISALLOW_FILE_EDIT', true);

        $this->assertSame('file_mods', $this->commandWriter()->target('type', 'book', null)['reason']);
        $this->assertWriteError('file_mods', 403, fn () => $this->commandWriter()->write('type', 'book', '{"name": "Book"}', null, null));
        $this->assertFileDoesNotExist($this->root . '/proto-types/book/type.jsonc');
    }
}
