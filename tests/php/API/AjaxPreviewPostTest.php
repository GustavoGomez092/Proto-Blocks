<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\AjaxHandler;

/**
 * AjaxHandler::renderWithPreviewPost(), used by the admin-ajax preview with
 * the posted post_id: same validation as the REST postId, globals restored.
 * Separate processes: the stubs define WP_Post.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AjaxPreviewPostTest extends TestCase
{
    private const EDITOR = 7;
    private const SUBSCRIBER = 8;

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
        unset($GLOBALS['post']);
    }

    private function seen(mixed $postId): ?int
    {
        return AjaxHandler::renderWithPreviewPost(
            $postId,
            static fn() => ($GLOBALS['post'] ?? null) instanceof \WP_Post ? $GLOBALS['post']->ID : null
        );
    }

    public function test_post_id_is_honoured_for_an_editor_and_globals_restored(): void
    {
        $previous = $GLOBALS['pb_test_posts'][11];
        $GLOBALS['post'] = $previous;

        $this->assertSame(10, $this->seen('10'));
        $this->assertSame($previous, $GLOBALS['post']);
        $this->assertSame([10, 11], $GLOBALS['pb_test_setup_postdata']);
    }

    public function test_no_previous_post_is_restored_as_null(): void
    {
        $this->assertSame(10, $this->seen('10'));
        $this->assertNull($GLOBALS['post']);
    }

    public function test_ignored_for_a_user_who_cannot_edit_the_post(): void
    {
        $GLOBALS['pb_test_current_user'] = self::SUBSCRIBER;

        $this->assertNull($this->seen('10'));
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertArrayNotHasKey('post', $GLOBALS);
    }

    public function test_malformed_or_missing_ids_are_ignored(): void
    {
        foreach ([null, '', '0', '-10', '10x', "10\n", ' 10', '1.5', [10], '999'] as $postId) {
            $this->assertNull($this->seen($postId), var_export($postId, true));
        }
        $this->assertSame([], $GLOBALS['pb_test_setup_postdata']);
        $this->assertArrayNotHasKey('post', $GLOBALS);
    }

    public function test_globals_restored_when_the_render_throws(): void
    {
        $previous = $GLOBALS['pb_test_posts'][11];
        $GLOBALS['post'] = $previous;

        try {
            AjaxHandler::renderWithPreviewPost('10', static function (): never {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
            $this->assertSame($previous, $GLOBALS['post']);
        }
    }
}
