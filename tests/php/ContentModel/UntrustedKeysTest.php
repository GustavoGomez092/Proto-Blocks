<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\SchemaCheck;
use ProtoBlocks\ContentModel\UntrustedKeys;

require_once __DIR__ . '/../CLI/wp-stubs.php';

/**
 * Spec §6: declaration files are data. The schema refuses keys naming PHP
 * code and markup in labels (so saves return 400 and the loader skips the
 * file); PHP declarations keep both.
 */
final class UntrustedKeysTest extends TestCase
{
    /** @return array<string, array<string, mixed>> kind => a minimal valid declaration */
    private static function bases(): array
    {
        return [
            'type' => ['name' => 'Product'],
            'taxonomy' => ['name' => 'Genre'],
            'options' => ['title' => 'Theme Settings'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{path: string, message: string}>
     */
    private static function check(string $kind, array $data): array
    {
        return SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/' . $kind);
    }

    public function testSchemaRefusesUnsafeKeysForEveryKind(): void
    {
        foreach (self::bases() as $kind => $base) {
            $this->assertSame([], self::check($kind, $base), $kind);
            foreach (UntrustedKeys::UNSAFE_KEYS as $key) {
                $errors = self::check($kind, $base + [$key => 'phpinfo']);
                if ($key === 'meta_box_cb' && $kind !== 'options') {
                    // Only the literal false is allowed (core hides a taxonomy box with it).
                    $this->assertSame('/meta_box_cb', $errors[0]['path'], $kind);
                    $this->assertSame([], self::check($kind, $base + [$key => false]), $kind);
                    $this->assertNotSame([], self::check($kind, $base + [$key => true]), $kind);
                    continue;
                }
                $this->assertSame([['path' => '/' . $key, 'message' => 'Not allowed in a declaration file.']], $errors, "$kind $key");
            }
        }
    }

    public function testSchemaRefusesUnsafeKeysInInlineTaxonomies(): void
    {
        $errors = self::check('type', ['name' => 'Book', 'taxonomies' => ['category', ['name' => 'Genre', 'meta_box_sanitize_cb' => 'phpinfo']]]);
        $this->assertNotSame([], $errors);
        $this->assertSame('/taxonomies/1/meta_box_sanitize_cb', $errors[0]['path']);
    }

    public function testSchemaRefusesMarkupInLabels(): void
    {
        $cases = [
            ['type', ['name' => 'Product', 'labels' => ['menu_name' => '<b>Shop</b>']], '/labels/menu_name'],
            ['type', ['name' => 'Pro>duct'], '/name'],
            ['type', ['name' => 'Product', 'plural' => '<Products'], '/plural'],
            ['taxonomy', ['name' => 'Genre', 'labels' => ['all_items' => 'All <i>']], '/labels/all_items'],
            ['type', ['name' => 'Book', 'taxonomies' => [['name' => '<Genre>']]], '/taxonomies/0/name'],
            ['options', ['title' => '<script>'], '/title'],
            ['options', ['title' => 'Settings', 'menu_title' => 'a>b'], '/menu_title'],
            ['options', ['title' => 'Settings', 'menuTitle' => 'a<b'], '/menuTitle'],
            ['options', ['title' => 'Settings', 'tabs' => [['title' => '<x>']]], '/tabs/0/title'],
            ['options', ['title' => 'Settings', 'children' => [['slug' => 'more', 'title' => '<x>']]], '/children/0/title'],
        ];
        foreach ($cases as [$kind, $data, $path]) {
            $errors = self::check($kind, $data);
            $this->assertNotSame([], $errors, $path);
            $this->assertSame($path, $errors[0]['path']);
        }

        // Ordinary punctuation is fine.
        $this->assertSame([], self::check('type', ['name' => 'Q&A', 'labels' => ['menu_name' => 'Shop "new" & more!']]));
    }

    public function testPhpDeclarationsAreNotRefusedForTrustedKeysOrLabelText(): void
    {
        $declaration = new Declaration(
            Declaration::TYPE,
            'book',
            [
                'name' => 'Book',
                'register_meta_box_cb' => static function (): void {
                },
                'labels' => ['menu_name' => 'Books <span class="count">new</span>'],
                'taxonomies' => [['name' => 'Genre', 'meta_box_cb' => false]],
            ],
            Declaration::ORIGIN_PHP,
            '/theme/functions.php:3',
            null
        );

        $this->assertSame([], PostTypeArgs::schemaIssues($declaration, '#/$defs/type'));
    }

    public function testNamesCallable(): void
    {
        $this->assertFalse(UntrustedKeys::namesCallable('strlen'));
        $this->assertFalse(UntrustedKeys::namesCallable('Link'));
        $this->assertFalse(UntrustedKeys::namesCallable('Date'));
        $this->assertTrue(UntrustedKeys::namesCallable('\strlen'));
        $this->assertTrue(UntrustedKeys::namesCallable('ProtoBlocks\\ContentModel\\UntrustedKeys::filter'));
        $this->assertTrue(UntrustedKeys::namesCallable(UntrustedKeys::class . '::filter'));
        $this->assertTrue(UntrustedKeys::namesCallable([UntrustedKeys::class, 'filter']));
        $this->assertFalse(UntrustedKeys::namesCallable('post'));
        $this->assertFalse(UntrustedKeys::namesCallable(''));
        $this->assertFalse(UntrustedKeys::namesCallable('No\Such\ClassPb::method'));
        $this->assertFalse(UntrustedKeys::namesCallable(['book', 'books']));
        $this->assertFalse(UntrustedKeys::namesCallable(true));
        $this->assertFalse(UntrustedKeys::namesCallable(['title', 'editor', 'thumbnail']));
    }

    private static function fileType(array $data): Declaration
    {
        return new Declaration('type', 'product', $data, Declaration::ORIGIN_FILE, '/theme/proto-types/product/type.jsonc', '/theme');
    }

    public function testPlainWordsAreKeptButNamedCallablesAreStripped(): void
    {
        [$kept, $issues] = UntrustedKeys::filter(self::fileType(['name' => 'Product', 'description' => 'Link', 'foo' => 'Date']), '#/$defs/type');
        $this->assertSame([], $issues);
        $this->assertSame('Link', $kept->data['description']);

        [$stripped, $issues] = UntrustedKeys::filter(self::fileType(['name' => 'Product', 'foo' => UntrustedKeys::class . '::filter']), '#/$defs/type');
        $this->assertArrayNotHasKey('foo', $stripped->data);
        $this->assertSame(['unsafe_key'], array_map(static fn ($i) => $i->code, $issues));
    }

    public function testMetaBoxCbFalseIsKeptAndStringIsStripped(): void
    {
        [$kept, $issues] = UntrustedKeys::filter(self::fileType(['name' => 'Product', 'meta_box_cb' => false]), '#/$defs/type');
        $this->assertSame([], $issues);
        $this->assertFalse($kept->data['meta_box_cb']);

        [$stripped] = UntrustedKeys::filter(self::fileType(['name' => 'Product', 'meta_box_cb' => 'post_categories_meta_box']), '#/$defs/type');
        $this->assertArrayNotHasKey('meta_box_cb', $stripped->data);
    }
}
