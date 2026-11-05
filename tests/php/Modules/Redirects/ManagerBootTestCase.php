<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations as ContentModelPhpDeclarations;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Manager;
use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Scripts\ScriptsModule;

require_once dirname(__DIR__, 2) . '/CLI/wp-stubs.php';
require_once dirname(__DIR__, 2) . '/ContentModel/stubs/hooks.php';
require_once dirname(__DIR__, 4) . '/includes/content-model.php';
require_once dirname(__DIR__, 4) . '/includes/Modules/Fields/functions.php';

/**
 * Boots the whole Modules\Manager over the temp child theme, with the stub
 * hook recorder, a recording $wpdb and every static the boot touches reset.
 * Redirection is "not active" unless a test says otherwise.
 */
abstract class ManagerBootTestCase extends RedirectsTestCase
{
    private const STATE = [
        'pb_test_options', 'pb_test_cron', 'pb_test_actions', 'pb_test_redirects', 'pb_test_registered',
        'pb_test_post_types', 'pb_test_taxonomies', 'pb_test_tax_links', 'pb_test_query',
    ];

    /** @var array<string, mixed> */
    private array $serverBackup = [];
    /** @var array<string, mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        foreach (self::STATE as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_dbdelta'] = [];
        $GLOBALS['pb_test_is_admin'] = false;
        unset($GLOBALS['pb_test_status'], $GLOBALS['pb_test_current_user'], $GLOBALS['wp_query']);
        ContentModelPhpDeclarations::reset();
        Coexistence::setDetector(static fn(): bool => false);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        foreach (self::STATE as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_is_admin'] = false;
        unset($GLOBALS['pb_test_dbdelta'], $GLOBALS['pb_test_status'], $GLOBALS['pb_test_nocache'], $GLOBALS['pb_test_current_user'], $GLOBALS['wp_query']);
        ContentModelPhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        RedirectsModule::setInstance(null);
        RedirectsModule::setResponseHandlers(null, null);
        ScriptsModule::setInstance(null);
        Coexistence::setDetector(null);
        Ip::setResolver(null);
        parent::tearDown();
    }

    /** A freshly booted Manager over the child theme (hooks recorded from scratch). */
    protected function bootManager(): Manager
    {
        $GLOBALS['pb_test_hooks'] = [];
        $child = $this->child;
        $manager = new Manager(new ContentModelRegistry(new FileLoader(), static fn(): array => [$child]));
        $manager->boot();

        return $manager;
    }

    protected function putGroup(string $slug, string $text): void
    {
        $this->put($this->child, 'proto-redirects/' . $slug . '/redirects.jsonc', $text);
    }

    protected function putSettings(string $text): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', $text);
    }
}
