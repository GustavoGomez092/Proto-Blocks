<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\RestAPI;
use ProtoBlocks\Blocks\Registrar;
use ProtoBlocks\Controls\OptionsProviders;
use ProtoBlocks\Template\Cache;
use ProtoBlocks\Template\Engine;

/**
 * The /preview route's optional postId: the preview renders with that post as
 * the global post only for a user who may edit it (bootstrap current_user_can
 * reads pb_test_caps), and the previous global is restored afterwards.
 * Separate processes: the stubs define WP_Post.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RestApiPreviewPostTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../fixtures/block-probe.php';
    private const EDITOR = 7;
    private const SUBSCRIBER = 8;

    /** @var list<mixed> the global post seen by each renderPreview() call */
    private array $seen = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/stubs/PreviewPost.php';
        require_once __DIR__ . '/content-model-stubs.php';
        require_once __DIR__ . '/../Modules/Fields/wp-stubs.php';

        $GLOBALS['pb_test_posts'] = [
            10 => new \WP_Post(['ID' => 10, 'post_type' => 'event']),
            11 => new \WP_Post(['ID' => 11, 'post_type' => 'event']),
        ];
        $GLOBALS['pb_test_caps'] = [self::EDITOR => ['edit_posts', 'edit_post'], self::SUBSCRIBER => ['read']];
        $GLOBALS['pb_test_current_user'] = self::EDITOR;
        $GLOBALS['pb_test_setup_postdata'] = [];
        $GLOBALS['pb_test_routes'] = [];
        unset($GLOBALS['post']);
    }

    private function api(?Engine $engine = null): RestAPI
    {
        if ($engine === null) {
            $engine = $this->createMock(Engine::class);
            $engine->method('renderPreview')->willReturnCallback(function (): string {
                $this->seen[] = $GLOBALS['post'] ?? null;
                return '<div>ok</div>';
            });
        }
        $registrar = $this->createMock(Registrar::class);
        $registrar->method('getRegisteredBlocks')->willReturn([
            'probe' => ['path' => dirname(self::TEMPLATE), 'schema' => ['protoBlocks' => ['templatePath' => self::TEMPLATE]]],
        ]);

        return new RestAPI($engine, $registrar, $this->createMock(Cache::class), $this->createMock(OptionsProviders::class));
    }

    private function preview(mixed $postId, ?Engine $engine = null): mixed
    {
        $request = new \WP_REST_Request();
        $request->set_param('template', 'probe');
        $request->set_param('attributes', []);
        if ($postId !== null) {
            $request->set_param('postId', $postId);
        }

        return $this->api($engine)->generatePreview($request);
    }

    /** @return list<int|null> the global post ID seen by each render */
    private function seenIds(): array
    {
        return array_map(static fn($p): ?int => $p instanceof \WP_Post ? $p->ID : null, $this->seen);
    }

    public function testRouteDeclaresAnOptionalPositiveIntegerPostId(): void
    {
        $this->api()->registerRoutes();
        $preview = null;
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            if ($route['namespace'] === 'proto-blocks/v1' && $route['route'] === '/preview') {
                $preview = $route['args'];
            }
        }

        $this->assertIsArray($preview);
        $this->assertSame(['required' => false, 'type' => 'integer', 'minimum' => 1], $preview['args']['postId'] ?? null);
    }

    public function testEditorOfThePostPreviewsWithItAndTheGlobalIsRestored(): void
    {
        $previous = $GLOBALS['pb_test_posts'][11];
        $GLOBALS['post'] = $previous;

        $response = $this->preview(10);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([10], $this->seenIds(), 'post 10 is the global post during the render');
        $this->assertSame($previous, $GLOBALS['post'], 'previous global post restored');
        $this->assertSame([10, 11], $GLOBALS['pb_test_setup_postdata']);
    }

    public function testNoPreviousPostIsRestoredAsNull(): void
    {
        $this->preview('10');

        $this->assertSame([10], $this->seenIds());
        $this->assertNull($GLOBALS['post']);
    }

    public function testUserWhoCannotEditThePostGetsAPreviewWithoutIt(): void
    {
        $GLOBALS['pb_test_current_user'] = self::SUBSCRIBER;

        $response = $this->preview(10);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([null], $this->seenIds(), 'postId ignored');
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertArrayNotHasKey('post', $GLOBALS);
    }

    public function testMalformedOrMissingPostsAreIgnored(): void
    {
        foreach ([null, 0, -10, '10x', "10\n", 1.5, [10], 999] as $postId) {
            $this->preview($postId);
        }

        $this->assertSame(array_fill(0, 8, null), $this->seenIds());
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertArrayNotHasKey('post', $GLOBALS);
    }

    public function testGlobalIsRestoredWhenTheRenderThrows(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('renderPreview')->willReturnCallback(function (): string {
            $this->seen[] = $GLOBALS['post'] ?? null;
            throw new \RuntimeException('boom');
        });
        // errorMessage() error_log()s the failure; keep it off the child's stderr.
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'pb-preview-log'));

        $this->assertInstanceOf(\WP_Error::class, $this->preview(10, $engine));
        $this->assertSame([10], $this->seenIds(), 'the post was set before the throw');
        $this->assertNull($GLOBALS['post'] ?? null);
    }
}
