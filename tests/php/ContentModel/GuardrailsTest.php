<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Guardrails;

require_once __DIR__ . '/../CLI/wp-stubs.php';

final class GuardrailsTest extends TestCase
{
    /**
     * @param array<string, int> $posts
     * @param array<string, int> $terms
     */
    private static function guardrails(array $posts = [], array $terms = []): Guardrails
    {
        return new Guardrails(
            static fn (string $type): int => $posts[$type] ?? 0,
            static fn (string $taxonomy): int => $terms[$taxonomy] ?? 0
        );
    }

    public function testSupportsRemovedWithPosts(): void
    {
        $issues = self::guardrails(['book' => 3])->checkSave(
            'type',
            'book',
            ['name' => 'Book', 'supports' => ['title', 'editor']],
            ['name' => 'Book', 'supports' => ['title']]
        );
        $this->assertCount(1, $issues);
        $this->assertSame('supports_removed', $issues[0]['code']);
        $this->assertSame(['editor'], $issues[0]['detail']);
        $this->assertSame(3, $issues[0]['count']);
        $this->assertStringContainsString('editor', $issues[0]['message']);
    }

    public function testSupportsRemovedWithoutPostsIsSilent(): void
    {
        $issues = self::guardrails()->checkSave(
            'type',
            'book',
            ['name' => 'Book', 'supports' => ['title', 'editor']],
            ['name' => 'Book', 'supports' => ['title']]
        );
        $this->assertSame([], $issues);
    }

    public function testDefaultSupportsAreTheBaseline(): void
    {
        $issues = self::guardrails(['book' => 1])->checkSave('type', 'book', ['name' => 'Book'], ['name' => 'Book', 'supports' => ['title']]);
        $this->assertSame(['editor', 'thumbnail'], $issues[0]['detail']);
    }

    public function testSupportsFalseRemovesAllGuarded(): void
    {
        $issues = self::guardrails(['book' => 1])->checkSave('type', 'book', ['name' => 'Book'], ['name' => 'Book', 'supports' => false]);
        $this->assertSame(['title', 'editor', 'thumbnail'], $issues[0]['detail']);
    }

    public function testUnguardedSupportsAndUnchangedAreSilent(): void
    {
        $g = self::guardrails(['book' => 5]);
        $this->assertSame([], $g->checkSave('type', 'book', ['name' => 'Book'], ['name' => 'Book']));
        $this->assertSame([], $g->checkSave('type', 'book', ['name' => 'Book'], ['name' => 'Book', 'supports' => ['title', 'editor', 'thumbnail']]));
        $this->assertSame([], $g->checkSave('type', 'book', null, ['name' => 'Book', 'supports' => []]));
    }

    public function testTaxonomyRemovedWithTerms(): void
    {
        $issues = self::guardrails([], ['genre' => 5])->checkSave(
            'type',
            'book',
            ['name' => 'Book', 'taxonomies' => [['name' => 'Genre'], ['name' => 'Shelf']]],
            ['name' => 'Book', 'taxonomies' => [['name' => 'Shelf']]]
        );
        $this->assertCount(1, $issues);
        $this->assertSame('taxonomy_removed', $issues[0]['code']);
        $this->assertSame(['genre'], $issues[0]['detail']);
        $this->assertSame(5, $issues[0]['count']);
    }

    public function testTaxonomyRemovedHonoursExplicitSlugAndIgnoresEmptyTaxonomies(): void
    {
        $g = self::guardrails([], ['book-genre' => 2]);
        $issues = $g->checkSave(
            'type',
            'book',
            ['name' => 'Book', 'taxonomies' => [['name' => 'Genre', 'slug' => 'book-genre'], ['name' => 'Shelf'], 'post_tag']],
            ['name' => 'Book']
        );
        $this->assertSame(['book-genre'], $issues[0]['detail']);
    }

    public function testFieldRemoved(): void
    {
        $before = ['name' => 'Book', 'fields' => [['title' => 'Details', 'fields' => [['name' => 'subtitle'], ['name' => 'isbn']]]]];
        $after = ['name' => 'Book', 'fields' => [['title' => 'Details', 'fields' => [['name' => 'strapline'], ['name' => 'isbn']]]]];
        $issues = self::guardrails(['book' => 2])->checkSave('type', 'book', $before, $after);
        $this->assertCount(1, $issues);
        $this->assertSame('field_removed', $issues[0]['code']);
        $this->assertSame(['subtitle'], $issues[0]['detail']);
        $this->assertSame(2, $issues[0]['count']);
        $this->assertStringContainsString('subtitle', $issues[0]['message']);

        $this->assertSame([], self::guardrails()->checkSave('type', 'book', $before, $after));
    }

    public function testFieldMovedBetweenGroupsIsNotRemoved(): void
    {
        $before = ['name' => 'Book', 'fields' => [['fields' => [['name' => 'a']]], ['fields' => [['name' => 'b']]]]];
        $after = ['name' => 'Book', 'fields' => [['fields' => [['name' => 'a'], ['name' => 'b']]]]];
        $this->assertSame([], self::guardrails(['book' => 2])->checkSave('type', 'book', $before, $after));
    }

    public function testMalformedShapesDoNotThrow(): void
    {
        $g = self::guardrails(['book' => 1], ['x' => 1]);
        $before = ['name' => 'Book', 'supports' => 'title', 'taxonomies' => 'x', 'fields' => [1, ['fields' => 'no'], ['fields' => [1, ['name' => 2]]]]];
        $this->assertIsArray($g->checkSave('type', 'book', $before, ['fields' => 'x', 'taxonomies' => [null]]));
    }

    public function testTaxonomyKindNeverReportsSupports(): void
    {
        $g = self::guardrails(['genre' => 9], ['genre' => 4]);
        $issues = $g->checkSave(
            'taxonomy',
            'genre',
            ['name' => 'Genre', 'supports' => ['title', 'editor'], 'fields' => [['fields' => [['name' => 'colour']]]]],
            ['name' => 'Genre', 'supports' => []]
        );
        $this->assertSame(['field_removed'], array_column($issues, 'code'));
        $this->assertSame(4, $issues[0]['count']);
    }

    public function testContentFor(): void
    {
        $g = self::guardrails(['book' => 7], ['genre' => 3]);
        $this->assertSame(['count' => 7, 'taxonomies' => ['genre' => 3]], $g->contentFor('type', 'book', ['taxonomies' => [['name' => 'Genre']]]));
        $this->assertSame(['count' => 7, 'taxonomies' => []], $g->contentFor('type', 'book', null));
        $this->assertSame(['count' => 3, 'taxonomies' => []], $g->contentFor('taxonomy', 'genre', ['name' => 'Genre']));
    }

    /**
     * @param array<string, int>          $posts
     * @param array<string, list<string>> $registered post type => registered supports
     */
    private static function withRegistered(array $posts, array $registered): Guardrails
    {
        return new Guardrails(
            static fn (string $type): int => $posts[$type] ?? 0,
            static fn (string $taxonomy): int => 0,
            static fn (string $type): ?array => $registered[$type] ?? null
        );
    }

    public function testNewOverrideOfRegisteredTypeStartsFromRegisteredSupports(): void
    {
        $guardrails = self::withRegistered(['post' => 40], ['post' => ['title', 'editor', 'thumbnail', 'excerpt', 'comments']]);

        $issues = $guardrails->checkSave('type', 'post', null, ['supports' => ['title', 'excerpt']]);
        $this->assertCount(1, $issues);
        $this->assertSame('supports_removed', $issues[0]['code']);
        $this->assertSame(['editor', 'thumbnail'], $issues[0]['detail']);
        $this->assertSame(40, $issues[0]['count']);

        // An override file that leaves supports alone removes nothing.
        $this->assertSame([], $guardrails->checkSave('type', 'post', null, ['labels' => ['menu_name' => 'News']]));
    }

    public function testNewDeclarationOfUnregisteredTypeHasNoGuardrails(): void
    {
        $guardrails = self::withRegistered(['book' => 3], []);
        $this->assertSame([], $guardrails->checkSave('type', 'book', null, ['name' => 'Book', 'supports' => ['title']]));
        $this->assertSame([], $guardrails->checkSave('taxonomy', 'genre', null, ['name' => 'Genre']));
    }

    public function testOverrideWithoutSupportsUsesRegisteredNotPluginDefaults(): void
    {
        // A plugin type registered with title only: dropping nothing it has is not a removal.
        $guardrails = self::withRegistered(['event' => 9], ['event' => ['title']]);
        $this->assertSame([], $guardrails->checkSave('type', 'event', ['labels' => ['menu_name' => 'Events']], ['supports' => ['title']]));

        // A plugin type with editor and thumbnail: unticking thumbnail is caught though the file never listed supports.
        $guardrails = self::withRegistered(['event' => 9], ['event' => ['title', 'editor', 'thumbnail']]);
        $issues = $guardrails->checkSave('type', 'event', ['labels' => ['menu_name' => 'Events']], ['supports' => ['title', 'editor']]);
        $this->assertSame(['thumbnail'], $issues[0]['detail']);
    }
}
