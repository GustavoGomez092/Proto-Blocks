<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Slug;

require_once __DIR__ . '/../CLI/wp-stubs.php';

final class SlugTest extends TestCase
{
    public function testFromName(): void
    {
        $this->assertSame('product', Slug::fromName('Product', 20));
        $this->assertSame('case-study', Slug::fromName('Case Study', 20));
        $this->assertSame('a-very-long-post-typ', Slug::fromName('A Very Long Post Type Name', 20));
        $this->assertSame('a-very-long-post', Slug::fromName('A Very Long Post Type Name', 17));
    }

    public function testErrors(): void
    {
        $this->assertNull(Slug::error('product', 'type'));
        $this->assertSame('pattern', Slug::error('Product', 'type'));
        $this->assertSame('pattern', Slug::error('1x', 'type'));
        $this->assertSame('length', Slug::error(str_repeat('a', 21), 'type'));
        $this->assertNull(Slug::error(str_repeat('a', 32), 'taxonomy'));
        $this->assertSame('reserved', Slug::error('author', 'type'));
        $this->assertSame('reserved', Slug::error('wp_block', 'type'));
        $this->assertNull(Slug::error('post', 'type'));
        $this->assertSame('reserved', Slug::error('post', 'taxonomy'));
        $this->assertNull(Slug::error('category', 'taxonomy'));
        $this->assertSame('reserved', Slug::error('post_format', 'taxonomy'));
        $this->assertNull(Slug::error('oit_resource', 'type'));
        $this->assertSame('pattern', Slug::error("product\n", 'type'));
    }
}
