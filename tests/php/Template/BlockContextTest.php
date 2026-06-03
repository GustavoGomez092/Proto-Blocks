<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Template\Renderer;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

final class BlockContextTest extends TestCase
{
    private function renderer(): Renderer
    {
        return new Renderer(new FieldRegistry(), new ControlRegistry());
    }

    private function exec(Renderer $r, ?\WP_Block $block): string
    {
        $m = new \ReflectionMethod($r, 'executeTemplate');
        $m->setAccessible(true);
        $fixture = dirname(__DIR__) . '/fixtures/block-probe.php';
        return $m->invoke($r, $fixture, [], [], $block);
    }

    public function test_block_instance_is_exposed_on_frontend(): void
    {
        $this->assertStringContainsString('BLOCK=instance', $this->exec($this->renderer(), new \WP_Block()));
    }

    public function test_block_is_null_in_preview(): void
    {
        $this->assertStringContainsString('BLOCK=null', $this->exec($this->renderer(), null));
    }
}
