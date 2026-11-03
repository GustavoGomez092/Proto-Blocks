<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;

require_once __DIR__ . '/wp-stubs.php';

/**
 * Base for every test that touches the Fields stubs or module state.
 *
 * The WordPress stubs keep their state in `pb_test_*` globals, and the module
 * keeps process-wide statics (the shared FieldReader, the FieldsModule
 * instance, the PHP group registry). A test that leaks any of them breaks
 * whichever unrelated test runs next under --order-by=random, so both
 * setUp() and tearDown() wipe all of it. Subclasses call parent::setUp() /
 * parent::tearDown() and seed only what they need.
 */
abstract class FieldsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::resetModuleState();
    }

    protected function tearDown(): void
    {
        self::resetModuleState();
        parent::tearDown();
    }

    /** Stores tests read directly (assertSame([], ...)), so they exist as empty arrays. */
    private const STORES = [
        'pb_test_filters', 'pb_test_hooks', 'pb_test_actions', 'pb_test_meta_reg', 'pb_test_supports',
        'pb_test_meta', 'pb_test_meta_rows', 'pb_test_posts', 'pb_test_object_terms', 'pb_test_terms',
        'pb_test_users', 'pb_test_caps', 'pb_test_options', 'pb_test_autoload', 'pb_test_transients',
        'pb_test_bindings', 'pb_test_doing_it_wrong',
    ];

    /**
     * Unsetting restores each stub's documented default (logged out, not
     * multisite, block theme, empty stores, ...).
     */
    public static function resetModuleState(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (is_string($key) && str_starts_with($key, 'pb_test_')) {
                unset($GLOBALS[$key]);
            }
        }
        foreach (self::STORES as $store) {
            $GLOBALS[$store] = [];
        }
        unset($GLOBALS['wp_version']);
        FieldReader::setShared(null);
        FieldsModule::setInstance(null);
        PhpGroups::reset();
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
    }
}
