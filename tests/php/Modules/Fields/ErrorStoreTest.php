<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

require_once __DIR__ . '/wp-stubs.php';

final class ErrorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_transients'] = [];
        $GLOBALS['pb_test_posts'] = [10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft']];
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_transients'] = [];
        $GLOBALS['pb_test_posts'] = [];
    }

    public function testPutThenPeekKeepsTheEntry(): void
    {
        $store = new ErrorStore();
        $ref = ObjectRef::post(10);
        $store->put($ref, 1, ['start_date' => 'This field is required.'], ['start_date' => '']);

        $expected = ['errors' => ['start_date' => 'This field is required.'], 'submitted' => ['start_date' => '']];
        $this->assertSame($expected, $store->peek($ref, 1));
        $this->assertSame($expected, $store->peek($ref, 1), 'peek does not delete');
        $this->assertSame(300, $GLOBALS['pb_test_transients']['proto_fields_err_post_10_1']['expiration']);
    }

    public function testTakeReturnsAndClears(): void
    {
        $store = new ErrorStore();
        $ref = ObjectRef::post(10);
        $store->put($ref, 1, ['agenda.1.title' => 'This field is required.'], ['agenda' => [['title' => '']]]);

        $this->assertSame(['agenda.1.title' => 'This field is required.'], $store->take($ref, 1)['errors']);
        $this->assertNull($store->take($ref, 1));
        $this->assertNull($store->peek($ref, 1));
    }

    public function testEntriesAreKeyedPerUser(): void
    {
        $store = new ErrorStore();
        $ref = ObjectRef::post(10);
        $store->put($ref, 1, ['a' => 'x'], []);

        $this->assertNull($store->peek($ref, 2));
        $this->assertNull($store->take($ref, 2));
        $this->assertNotNull($store->peek($ref, 1), "user 2's take must not clear user 1's entry");
    }

    public function testKeysPerKind(): void
    {
        $store = new ErrorStore();
        $store->put(ObjectRef::term(5, 'category'), 3, ['a' => 'x'], []);
        $store->put(ObjectRef::user(7), 3, ['b' => 'y'], []);
        $store->put(ObjectRef::option('site-settings'), 3, ['c' => 'z'], []);

        $this->assertArrayHasKey('proto_fields_err_term_5_3', $GLOBALS['pb_test_transients']);
        $this->assertArrayHasKey('proto_fields_err_user_7_3', $GLOBALS['pb_test_transients']);
        $this->assertArrayHasKey('proto_fields_err_option_site-settings_3', $GLOBALS['pb_test_transients']);
        $this->assertNull($store->peek(ObjectRef::post(5), 3), 'a post and a term with the same id do not share an entry');
    }

    public function testNoErrorsClearsAStaleEntry(): void
    {
        $store = new ErrorStore();
        $ref = ObjectRef::post(10);
        $store->put($ref, 1, ['a' => 'x'], []);
        $store->put($ref, 1, [], []);

        $this->assertNull($store->peek($ref, 1));
    }

    public function testNoEntryForAnonymousUsers(): void
    {
        $store = new ErrorStore();
        $store->put(ObjectRef::user(7), 0, ['a' => 'x'], ['a' => 'v']);

        $this->assertSame([], $GLOBALS['pb_test_transients']);
        $this->assertNull($store->peek(ObjectRef::user(7), 0));
    }

    public function testOversizedSubmittedValuesAreDroppedButErrorsKept(): void
    {
        $store = new ErrorStore();
        $ref = ObjectRef::post(10);
        $rows = array_fill(0, 10001, ['title' => str_repeat('x', 20)]);
        $store->put($ref, 1, ['agenda' => 'Add no more than 500 rows.', 'start_date' => 'This field is required.'], ['agenda' => $rows, 'start_date' => '']);

        $entry = $store->peek($ref, 1);
        $this->assertSame('Add no more than 500 rows.', $entry['errors']['agenda']);
        $this->assertArrayNotHasKey('agenda', $entry['submitted']);
        $this->assertSame('', $entry['submitted']['start_date']);
        $this->assertLessThanOrEqual(ErrorStore::MAX_SUBMITTED_BYTES, strlen((string) json_encode($entry['submitted'])));
    }

    public function testMalformedTransientIsIgnored(): void
    {
        $GLOBALS['pb_test_transients']['proto_fields_err_post_10_1'] = ['value' => 'garbage', 'expiration' => 300];

        $this->assertNull((new ErrorStore())->peek(ObjectRef::post(10), 1));
    }
}
