<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\TaxonomyArgs;
use ProtoBlocks\Tests\ContentModel\Support\WpObjectProps;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/stubs/theme.php';
require_once __DIR__ . '/../../../includes/content-model.php';

/**
 * The Phase 1 done criterion: two live sites' post types (and CADCO's
 * taxonomies) re-declared as one line each plus a short override array
 * produce the same registered object.
 */
final class ParityTest extends TestCase
{
    private const RECON = __DIR__ . '/../fixtures/content-model/recon/';
    private const THEME_URL = 'https://example.test/wp-content/themes/t';

    private const OIT_TEMPLATE = [
        ['proto-blocks/oit-breadcrumbs-eyebrow-title', []],
        ['proto-blocks/oit-video-preview', []],
        ['core/spacer', ['height' => '48px']],
        ['proto-blocks/oit-text-image', []],
        ['core/spacer', ['height' => '48px']],
        ['proto-blocks/oit-form', []],
        ['core/spacer', ['height' => '48px']],
        ['proto-blocks/oit-related-resources', []],
        ['core/spacer', ['height' => '80px']],
        ['proto-blocks/oit-link-cards', []],
    ];

    protected function setUp(): void
    {
        PhpDeclarations::reset();
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
    }

    public function testCadcoResourceOneLine(): void
    {
        proto_post_type('Resource', ['has_archive' => false, 'menu_icon' => 'dashicons-media-document', 'rewrite' => ['slug' => 'resources']]);

        $this->assertPostTypeParity(require self::RECON . 'cadco-resource.php');
    }

    public function testOitResourceOneLine(): void
    {
        proto_post_type('Resource', [
            'slug'          => 'oit_resource',
            'has_archive'   => 'resources',
            'rewrite'       => ['slug' => 'resources'],
            'menu_icon'     => 'dashicons-portfolio',
            'supports'      => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'],
            'template'      => self::OIT_TEMPLATE,
            'template_lock' => false,
            'labels'        => [
                'featured_image'        => 'Cover Image',
                'set_featured_image'    => 'Set cover image',
                'remove_featured_image' => 'Remove cover image',
                'use_featured_image'    => 'Use as cover image',
            ],
        ]);

        $this->assertPostTypeParity(require self::RECON . 'oit-resource.php');
    }

    public function testCadcoTaxonomies(): void
    {
        proto_taxonomy('Media Type', ['slug' => 'resource_media', 'post_types' => ['resource'], 'hierarchical' => true, 'rewrite' => ['slug' => 'media-type']]);
        proto_taxonomy('Product', ['slug' => 'resource_product', 'post_types' => ['resource'], 'rewrite' => ['slug' => 'resource-product']]);

        $declared = PhpDeclarations::all()[Declaration::TAXONOMY];
        $this->assertSame([], PhpDeclarations::issues());
        foreach (require self::RECON . 'cadco-taxonomies.php' as $fixture) {
            $decl = $declared[$fixture['slug']] ?? null;
            $this->assertInstanceOf(Declaration::class, $decl, $fixture['slug']);

            $built = TaxonomyArgs::build($decl);
            $this->assertSame([], $built['issues']);
            $this->assertSame($fixture['object_types'], $built['objectTypes']);
            $this->assertSame(WpObjectProps::taxonomy($fixture['args']), WpObjectProps::taxonomy($built['args']), $fixture['slug']);
            $this->assertLabelsMatch($fixture['args']['labels'], $built['args']['labels']);
        }
    }

    /**
     * @param array{slug: string, args: array<string, mixed>} $fixture
     */
    private function assertPostTypeParity(array $fixture): void
    {
        $this->assertSame([], PhpDeclarations::issues());
        $declared = PhpDeclarations::all()[Declaration::TYPE];
        $this->assertSame([$fixture['slug']], array_keys($declared));

        $built = PostTypeArgs::build($declared[$fixture['slug']], sys_get_temp_dir(), self::THEME_URL);
        $this->assertSame([], $built['issues']);
        $this->assertSame(WpObjectProps::postType($fixture['args']), WpObjectProps::postType($built['args']));
        $this->assertLabelsMatch($fixture['args']['labels'], $built['args']['labels']);
    }

    /**
     * The only accepted difference is terminal punctuation.
     *
     * @param array<string, string> $expected
     * @param array<string, string> $actual
     */
    private function assertLabelsMatch(array $expected, array $actual): void
    {
        foreach ($expected as $key => $label) {
            $this->assertArrayHasKey($key, $actual);
            $this->assertSame(rtrim($label, '.'), rtrim($actual[$key], '.'), "label $key");
        }
    }
}
