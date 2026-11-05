<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\Engine\Request;

/**
 * Base for the engine tests: wipes the request/redirect stub globals before
 * and after each test, and builds rules and settings in the contract shapes.
 *
 * settings() mirrors the spec §1 defaults. Definition\Settings::defaults() is
 * Track A's; this helper keeps the engine tests independent of it.
 */
abstract class EngineTestCase extends TestCase
{
    private const GLOBALS = [
        'pb_test_filters', 'pb_test_hooks', 'pb_test_actions', 'pb_test_redirects', 'pb_test_status',
        'pb_test_nocache', 'pb_test_is_admin', 'pb_test_ssl', 'pb_test_home_url', 'pb_test_doing_cron',
        'pb_test_query', 'pb_test_current_user', 'pb_test_caps', 'pb_test_rest_prefix', 'pb_test_now',
        'wp_query',
    ];

    /** @var array<string,mixed> */
    private array $serverBackup = [];
    /** @var array<string,mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        self::reset();
    }

    protected function tearDown(): void
    {
        self::reset();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    private static function reset(): void
    {
        foreach (self::GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['pb_test_redirects'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_actions'] = [];
        Ip::setResolver(null);
    }

    /** @return array<string,mixed> spec §1 defaults */
    protected static function settings(array $overrides = []): array
    {
        $defaults = [
            'defaults' => ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'],
            'log' => [
                'redirects' => 7, 'notFound' => 7, 'ip' => 'none', 'headers' => false, 'external' => false,
                'trustedProxies' => [], 'ipHeaders' => [],
            ],
            'trackHits' => true,
            'cache' => 1,
            'monitor' => ['postTypes' => [], 'group' => 'modified-posts', 'trash' => false, 'associated' => ''],
            'headers' => ['site' => [], 'redirect' => []],
            'canonical' => ['https' => false, 'www' => '', 'aliases' => [], 'relocate' => ''],
            'coexist' => false,
        ];
        return array_replace_recursive($defaults, $overrides);
    }

    /** A normalised rule (contract shape) with $overrides applied. */
    protected static function rule(array $overrides = []): array
    {
        static $n = 0;
        $n++;
        $rule = [
            'id' => 'r_' . str_pad(dechex($n), 8, '0', STR_PAD_LEFT),
            'title' => '',
            'enabled' => true,
            'from' => '/old',
            'regex' => false,
            'to' => '/new',
            'else' => null,
            'code' => 301,
            'action' => 'url',
            'when' => null,
            'query' => 'exact',
            'ignoreCase' => true,
            'ignoreTrailingSlash' => true,
            'log' => true,
            'headers' => [],
            'group' => 'g',
            'origin' => 'file',
            'source' => '/abs/proto-redirects/g/redirects.jsonc',
            'index' => 0,
        ];
        return array_replace($rule, $overrides);
    }

    protected static function req(string $url, array $server = [], array $cookies = []): Request
    {
        return Request::fromUrl($url, $server + ['HTTP_HOST' => 'example.test'], $cookies);
    }
}
