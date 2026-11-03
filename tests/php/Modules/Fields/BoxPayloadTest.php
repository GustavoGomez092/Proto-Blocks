<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Edit\BoxPayload;
use ProtoBlocks\Modules\Fields\Edit\ChoiceResolver;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';

final class BoxPayloadTest extends TestCase
{
    private const ADMIN = 1;
    private const AUTHOR = 2;
    private const EVENT_GROUP = 'group_6543a1b2c3d4e';

    /** @var list<FieldGroup> */
    private array $groups = [];

    protected function setUp(): void
    {
        foreach (['pb_test_filters', 'pb_test_hooks', 'pb_test_meta', 'pb_test_meta_reg', 'pb_test_transients', 'pb_test_attachments', 'pb_test_actions'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'][self::ADMIN] = ['edit_posts', 'edit_post', 'unfiltered_html'];
        $GLOBALS['pb_test_caps'][self::AUTHOR] = ['edit_posts', 'edit_post'];
        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft', 'post_author' => self::AUTHOR],
            30 => ['ID' => 30, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Hero'],
        ];
        $GLOBALS['pb_test_current_user'] = self::AUTHOR;
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/fields/acf-export-realistic.json'), true);
        $this->groups = GroupNormalizer::normalizeMany($raw, 'file:acf-export')['groups'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_caps'][self::ADMIN], $GLOBALS['pb_test_caps'][self::AUTHOR]);
        foreach (['pb_test_meta', 'pb_test_posts', 'pb_test_transients', 'pb_test_attachments'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
    }

    /** @param array<string, mixed> $raw */
    private function addGroup(array $raw): FieldGroup
    {
        $out = GroupNormalizer::normalize($raw + ['location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]]], 'php');
        $this->assertTrue($out['report']->ok(), (string) json_encode($out['report']->errors));
        $this->groups[] = $out['group'];

        return $out['group'];
    }

    private function payload(?ChoiceResolver $choices = null, ?ErrorStore $errors = null): BoxPayload
    {
        $index = new GroupIndex($this->groups);
        $types = TypeRegistry::withCoreTypes();
        $stores = [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($index),
        ];

        return new BoxPayload($types, new FieldReader($index, $types, $stores), $errors ?? new ErrorStore(), $choices ?? new ChoiceResolver(static fn(): array => []));
    }

    private function event(): FieldGroup
    {
        foreach ($this->groups as $g) {
            if ($g->key === self::EVENT_GROUP) {
                return $g;
            }
        }
        $this->fail('realistic group missing');
    }

    public function testShapeMatchesTheClientType(): void
    {
        $p = $this->payload()->build($this->event(), ObjectRef::post(10), self::ADMIN);
        foreach (['groupKey', 'fields', 'values', 'display', 'choices', 'errors', 'canUnfiltered', 'maxRows', 'restRoot', 'restNonce', 'object', 'readOnly'] as $key) {
            $this->assertArrayHasKey($key, $p);
        }
        $this->assertSame(self::EVENT_GROUP, $p['groupKey']);
        $this->assertSame('post:10', $p['object']);
        $this->assertTrue($p['canUnfiltered']);
        $this->assertSame($this->event()->fields[1]->toArray(), $p['fields'][1]);
        $this->assertArrayHasKey('agenda', $p['maxRows']);
    }

    public function testAuthorNeverReceivesStoredCode(): void
    {
        $g = $this->addGroup(['key' => 'group_code', 'title' => 'Code', 'fields' => [
            ['key' => 'f_tracking', 'name' => 'tracking', 'type' => 'code'],
            ['key' => 'f_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_snippet', 'name' => 'snippet', 'type' => 'code']]],
            ['key' => 'f_plain', 'name' => 'plain', 'type' => 'text'],
        ]]);
        $GLOBALS['pb_test_meta']['post'][10]['tracking'] = ['<script>track()</script>'];
        $GLOBALS['pb_test_meta']['post'][10]['rows'] = [[['snippet' => '<script>x()</script>']]];
        $GLOBALS['pb_test_meta']['post'][10]['plain'] = ['Hi'];

        $p = $this->payload()->build($g, ObjectRef::post(10), self::AUTHOR);
        $this->assertFalse($p['canUnfiltered']);
        $this->assertSame(['tracking' => 'unfiltered_html', 'rows' => 'unfiltered_html'], $p['readOnly']);
        $this->assertSame(['plain' => 'Hi'], $p['values']);
        $this->assertStringNotContainsString('track()', BoxPayload::json($p));
        $this->assertStringNotContainsString('x()', BoxPayload::json($p));

        $admin = $this->payload()->build($g, ObjectRef::post(10), self::ADMIN);
        $this->assertSame('<script>track()</script>', $admin['values']['tracking']);
        $this->assertSame([], $admin['readOnly']);
    }

    public function testErrorStoreEntryOverridesTheStoredValueOnce(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['start_date'] = ['2026-03-04'];
        $store = new ErrorStore();
        $store->put(ObjectRef::post(10), self::AUTHOR, ['start_date' => 'Enter a valid date.', 'agenda.1.title' => 'This field is required.', 'other' => 'x'], ['start_date' => '2026-02-30']);
        $builder = $this->payload(null, $store);

        $p = $builder->build($this->event(), ObjectRef::post(10), self::AUTHOR);
        $this->assertSame(['start_date' => 'Enter a valid date.', 'agenda.1.title' => 'This field is required.'], $p['errors']);
        $this->assertSame('2026-02-30', $p['values']['start_date']);
        $this->assertNull($store->peek(ObjectRef::post(10), self::AUTHOR), 'taken once');

        $again = $builder->build($this->event(), ObjectRef::post(10), self::AUTHOR);
        $this->assertSame($p['errors'], $again['errors'], 'a second box of the object sees the same read');
    }

    public function testLegacyAcfDateIsConverted(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['start_date'] = ['20260304'];
        $p = $this->payload()->build($this->event(), ObjectRef::post(10), self::AUTHOR);
        $this->assertSame('2026-03-04', $p['values']['start_date']);
    }

    public function testImageDisplayIsPreResolved(): void
    {
        $GLOBALS['pb_test_attachments'][30] = ['url' => 'https://example.test/hero.jpg', 'width' => 800, 'height' => 600, 'mime' => 'image/jpeg'];
        $GLOBALS['pb_test_meta']['post'][30]['_wp_attachment_image_alt'] = ['A stage'];
        $GLOBALS['pb_test_meta']['post'][10]['hero'] = [30];

        $p = $this->payload()->build($this->event(), ObjectRef::post(10), self::AUTHOR);
        $this->assertSame(['id' => 30, 'url' => 'https://example.test/hero.jpg', 'alt' => 'A stage'], $p['display']['hero']);
        $this->assertSame(30, $p['values']['hero']);
    }

    public function testRelationshipDisplayHidesUnreadablePosts(): void
    {
        $GLOBALS['pb_test_posts'][40] = ['ID' => 40, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Ada & Grace'];
        $GLOBALS['pb_test_posts'][41] = ['ID' => 41, 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Secret'];
        $GLOBALS['pb_test_meta']['post'][10]['speakers'] = [[40, 41]];

        $p = $this->payload()->build($this->event(), ObjectRef::post(10), self::AUTHOR);
        $this->assertSame([['id' => 40, 'title' => 'Ada & Grace', 'type' => 'post', 'status' => 'publish']], $p['display']['speakers']);
    }

    public function testOptionsSourceChoicesComeFromTheResolver(): void
    {
        $g = $this->addGroup(['key' => 'group_src', 'title' => 'Source', 'fields' => [
            ['key' => 'f_pick', 'name' => 'pick', 'type' => 'select', 'optionsSource' => 'wp:posts'],
            ['key' => 'f_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'f_inner', 'name' => 'inner', 'type' => 'select', 'optionsSource' => 'wp:terms']]],
        ]]);
        $calls = [];
        $choices = new ChoiceResolver(static function (string $source) use (&$calls): array {
            $calls[] = $source;
            return [['key' => '7', 'label' => 'Seven']];
        });

        $p = $this->payload($choices)->build($g, ObjectRef::post(10), self::AUTHOR);
        $this->assertSame([['key' => '7', 'label' => 'Seven']], $p['choices']['pick']);
        $this->assertSame([['key' => '7', 'label' => 'Seven']], $p['choices']['inner'], 'nested optionsSource fields too');
        $this->assertSame(['wp:posts', 'wp:terms'], $calls);
    }

    public function testNewObjectUsesDefaultsAndNoErrors(): void
    {
        $g = $this->addGroup(['key' => 'group_new', 'title' => 'New', 'fields' => [['key' => 'f_a', 'name' => 'a', 'type' => 'text', 'default_value' => 'hello']]]);
        $GLOBALS['pb_test_meta']['user'][0]['a'] = ['someone else'];
        $p = $this->payload()->build($g, ObjectRef::user(0), 0);
        $this->assertSame('user:new', $p['object']);
        $this->assertSame(['a' => 'hello'], $p['values']);
        $this->assertSame('', $p['restNonce']);
    }

    public function testMarkupKeepsThePayloadInsideItsScript(): void
    {
        $html = BoxPayload::markup(['title' => 'T <b>', 'fields' => [], 'values' => ['x' => '</script><script>alert(1)</script>', 'y' => "it's \"q\" & <!--"]]);
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringContainsString('</script>', $html);
        $this->assertStringNotContainsString('<!--', $html);
        $this->assertStringContainsString('T &lt;b&gt;', $html);
        $this->assertStringContainsString('data-proto-fields-box', $html);
        $this->assertSame(1, preg_match('/<script type="application\/json">(.*)<\/script>/sD', $html, $m));
        $this->assertSame('</script><script>alert(1)</script>', json_decode($m[1], true)['values']['x']);
    }
}
