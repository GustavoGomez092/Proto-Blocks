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
 * the global post only for a user who may edit it, and the previous global is
 * restored afterwards. Separate processes: the stubs define WP_Post.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RestApiPreviewPostTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../fixtures/block-probe.php';

    /** @var list<mixed> the global post seen by each renderPreview() call */
    private array $seen = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/stubs/PreviewPost.php';
        require_once __DIR__ . '/../Security/wp-stubs.php';

        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_type' => 'event'],
            11 => ['ID' => 11, 'post_type' => 'event'],
        ];
        $GLOBALS['pb_test_editable'] = [];
        $GLOBALS['pb_test_cap_checks'] = [];
        $GLOBALS['pb_test_setup_postdata'] = [];
        $GLOBALS['pb_test_current_user'] = 0;
        unset($GLOBALS['post']);
    }

    private function api(): RestAPI
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('renderPreview')->willReturnCallback(function (): string {
            $this->seen[] = $GLOBALS['post'] ?? null;
            return '<div>ok</div>';
        });
        $registrar = $this->createMock(Registrar::class);
        $registrar->method('getRegisteredBlocks')->willReturn([
            'probe' => ['path' => dirname(self::TEMPLATE), 'schema' => ['protoBlocks' => ['templatePath' => self::TEMPLATE]]],
        ]);

        return new RestAPI($engine, $registrar, $this->createMock(Cache::class), $this->createMock(OptionsProviders::class));
    }

    private function preview(mixed $postId): mixed
    {
        $request = new \WP_REST_Request();
        $request->set_param('template', 'probe');
        $request->set_param('attributes', []);
        if ($postId !== null) {
            $request->set_param('postId', $postId);
        }

        return $this->api()->generatePreview($request);
    }

    private function seenId(): ?int
    {
        $post = $this->seen[0] ?? null;

        return $post instanceof \WP_Post ? $post->ID : null;
    }

    public function testRouteDeclaresAnOptionalPositiveIntegerPostId(): void
    {
        $this->api()->registerRoutes();
        $arg = $GLOBALS['pb_test_routes']['proto-blocks/v1/preview']['args']['postId'] ?? null;

        $this->assertSame(['required' => false, 'type' => 'integer', 'minimum' => 1], $arg);
    }

    public function testEditorOfThePostPreviewsWithItAndTheGlobalIsRestored(): void
    {
        $GLOBALS['pb_test_editable'][10] = true;
        $previous = new \WP_Post(['ID' => 11, 'post_type' => 'event']);
        $GLOBALS['post'] = $previous;

        $response = $this->preview(10);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(10, $this->seenId(), 'post 10 is the global post during the render');
        $this->assertContains(['edit_post', 10], $GLOBALS['pb_test_cap_checks']);
        $this->assertSame($previous, $GLOBALS['post'], 'previous global post restored');
        $this->assertSame([10, 11], $GLOBALS['pb_test_setup_postdata']);
    }

    public function testNoPreviousPostIsRestoredAsNull(): void
    {
        $GLOBALS['pb_test_editable'][10] = true;

        $this->preview('10');

        $this->assertSame(10, $this->seenId());
        $this->assertNull($GLOBALS['post']);
    }

    public function testUserWhoCannotEditThePostGetsAPreviewWithoutIt(): void
    {
        $GLOBALS['pb_test_editable'][11] = true; // another post only

        $response = $this->preview(10);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($this->seenId(), 'postId ignored');
        $this->assertContains(['edit_post', 10], $GLOBALS['pb_test_cap_checks']);
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertArrayNotHasKey('post', $GLOBALS);
    }

    public function testMalformedOrMissingPostsAreIgnoredWithoutACapabilityCheck(): void
    {
        $GLOBALS['pb_test_editable'] = [10 => true, 999 => true];

        foreach ([null, 0, -10, '10x', "10\n", 1.5, [10]] as $postId) {
            $this->preview($postId);
        }
        $this->assertSame([], $GLOBALS['pb_test_cap_checks']);

        $this->preview(999); // editable but does not exist
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertSame([null, null, null, null, null, null, null, null], array_map(static fn($p) => $p instanceof \WP_Post ? $p->ID : null, $this->seen));
    }

    public function testGlobalIsRestoredWhenTheRenderThrows(): void
    {
        $GLOBALS['pb_test_editable'][10] = true;
        $engine = $this->createMock(Engine::class);
        $engine->method('renderPreview')->willThrowException(new \RuntimeException('boom'));
        $registrar = $this->createMock(Registrar::class);
        $registrar->method('getRegisteredBlocks')->willReturn([
            'probe' => ['path' => dirname(self::TEMPLATE), 'schema' => ['protoBlocks' => ['templatePath' => self::TEMPLATE]]],
        ]);
        $api = new RestAPI($engine, $registrar, $this->createMock(Cache::class), $this->createMock(OptionsProviders::class));
        $request = new \WP_REST_Request();
        $request->set_param('template', 'probe');
        $request->set_param('attributes', []);
        $request->set_param('postId', 10);
        // errorMessage() error_log()s the failure; keep it off the child's stderr.
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'pb-preview-log'));

        $this->assertInstanceOf(\WP_Error::class, $api->generatePreview($request));
        $this->assertNull($GLOBALS['post']);
    }
}
