<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Location;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\LocationMatcher;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

require_once __DIR__ . '/wp-stubs.php';

final class LocationMatcherTest extends TestCase
{
    private static function rule(string $param, string $op, string $value): array
    {
        return ['param' => $param, 'operator' => $op, 'value' => $value];
    }

    private static function one(string $param, string $op, string $value): Location
    {
        return new Location([[self::rule($param, $op, $value)]]);
    }

    private const POST = [
        'post_type' => 'event', 'post_status' => 'draft', 'post_id' => 42, 'page_template' => '',
        'terms' => ['category' => ['news', 'sport']],
    ];
    private const TERM = ['taxonomy' => 'product_cat'];
    private const USER = ['user_roles' => ['editor'], 'user_form' => 'edit'];
    private const OPTION = ['options_page' => 'theme-settings'];

    /** @return array<string, array{string, string, array, bool, bool}> param, value, ctx, == result, != result */
    public static function rules(): array
    {
        return [
            'post_type match' => ['post_type', 'event', self::POST, true, false],
            'post_type other' => ['post_type', 'page', self::POST, false, true],
            'post_status' => ['post_status', 'draft', self::POST, true, false],
            'post_status other' => ['post_status', 'publish', self::POST, false, true],
            'post_taxonomy' => ['post_taxonomy', 'category:news', self::POST, true, false],
            'post_taxonomy other slug' => ['post_taxonomy', 'category:tech', self::POST, false, true],
            'post_taxonomy other tax' => ['post_taxonomy', 'post_tag:news', self::POST, false, true],
            'page_template default' => ['page_template', 'default', self::POST, true, false],
            'page_template named' => ['page_template', 'templates/wide.php', self::POST, false, true],
            'post id' => ['post', '42', self::POST, true, false],
            'post id other' => ['post', '7', self::POST, false, true],
            'taxonomy' => ['taxonomy', 'product_cat', self::TERM, true, false],
            'taxonomy other' => ['taxonomy', 'category', self::TERM, false, true],
            'taxonomy all' => ['taxonomy', 'all', self::TERM, true, false],
            'user_role' => ['user_role', 'editor', self::USER, true, false],
            'user_role other' => ['user_role', 'administrator', self::USER, false, true],
            'user_role all' => ['user_role', 'all', self::USER, true, false],
            'user_form edit' => ['user_form', 'edit', self::USER, true, false],
            'user_form all' => ['user_form', 'all', self::USER, true, false],
            'options_page' => ['options_page', 'theme-settings', self::OPTION, true, false],
            'options_page other' => ['options_page', 'footer', self::OPTION, false, true],
        ];
    }

    #[DataProvider('rules')]
    public function testRule(string $param, string $value, array $ctx, bool $eq, bool $ne): void
    {
        $this->assertSame($eq, LocationMatcher::matches(self::one($param, '==', $value), $ctx), "$param == $value");
        $this->assertSame($ne, LocationMatcher::matches(self::one($param, '!=', $value), $ctx), "$param != $value");
    }

    public function testPageTemplateNamed(): void
    {
        $ctx = ['page_template' => 'templates/wide.php'] + self::POST;
        $this->assertTrue(LocationMatcher::matches(self::one('page_template', '==', 'templates/wide.php'), $ctx));
        $this->assertFalse(LocationMatcher::matches(self::one('page_template', '==', 'default'), $ctx));
    }

    public function testUserFormRegister(): void
    {
        $loc = self::one('user_form', '==', 'register');
        $this->assertTrue(LocationMatcher::matches($loc, ['user_roles' => [], 'user_form' => 'register']));
        $this->assertFalse(LocationMatcher::matches($loc, ['user_roles' => [], 'user_form' => 'edit']));
    }

    public function testRuleOfAnotherKindNeverMatches(): void
    {
        // A post rule, even negated, says nothing about a user screen.
        $this->assertFalse(LocationMatcher::matches(self::one('post_type', '!=', 'page'), self::USER));
        $this->assertFalse(LocationMatcher::matches(self::one('taxonomy', '!=', 'category'), self::POST));
        $this->assertFalse(LocationMatcher::matches(self::one('user_role', '!=', 'editor'), self::OPTION));
    }

    public function testUnsupportedNeverMatches(): void
    {
        $loc = new Location(
            [[self::rule('post_type', '==', 'event')], [self::rule('post_format', '==', 'video')]],
            false,
            ['post_format'],
        );
        $this->assertFalse(LocationMatcher::matches($loc, self::POST));
        $this->assertSame([], LocationMatcher::kinds($loc));
    }

    public function testOptionsPageUnknownNeverMatches(): void
    {
        $loc = self::one('options_page', '==', 'theme-settings');
        $this->assertFalse(LocationMatcher::matches($loc, []));
        $this->assertFalse(LocationMatcher::matches($loc, ['options_page' => '']));
        $this->assertFalse(LocationMatcher::matches(self::one('options_page', '!=', 'other'), ['options_page' => '']));
    }

    public function testMixedKindsBranchNeverMatches(): void
    {
        $loc = new Location([[self::rule('post_type', '==', 'event'), self::rule('user_role', '==', 'all')]]);
        $this->assertFalse(LocationMatcher::matches($loc, self::POST));
        $this->assertFalse(LocationMatcher::matches($loc, self::USER));
        $this->assertSame([], LocationMatcher::kinds($loc));
    }

    public function testOrOfAnd(): void
    {
        $loc = new Location([
            [self::rule('post_type', '==', 'event'), self::rule('post_status', '==', 'publish')],
            [self::rule('post_type', '==', 'event'), self::rule('post_taxonomy', '==', 'category:sport')],
            [self::rule('taxonomy', '==', 'all')],
        ]);
        $this->assertTrue(LocationMatcher::matches($loc, self::POST), 'second branch');
        $this->assertFalse(LocationMatcher::matches($loc, ['terms' => []] + self::POST), 'no branch');
        $this->assertTrue(LocationMatcher::matches($loc, self::TERM), 'third branch');
        $this->assertSame(['post', 'term'], LocationMatcher::kinds($loc));
    }

    public function testMalformedValuesNeverMatch(): void
    {
        $this->assertFalse(LocationMatcher::matches(self::one('post_taxonomy', '==', 'news'), self::POST));
        $this->assertFalse(LocationMatcher::matches(self::one('post_taxonomy', '!=', 'news'), self::POST));
        $this->assertFalse(LocationMatcher::matches(self::one('post', '==', '42abc'), self::POST));
        $this->assertFalse(LocationMatcher::matches(new Location([[self::rule('post_type', '~=', 'event')]]), self::POST));
    }

    public function testEmptyAndUnrenderable(): void
    {
        $this->assertFalse(LocationMatcher::matches(new Location([]), self::POST));
        $this->assertFalse(LocationMatcher::matches(new Location([[]]), self::POST));
    }

    public function testExplicitKindWins(): void
    {
        // An options page slug in a post context does not turn it into an options screen.
        $ctx = ['kind' => 'post'] + self::POST + self::OPTION;
        $this->assertFalse(LocationMatcher::matches(self::one('options_page', '==', 'theme-settings'), $ctx));
        $this->assertTrue(LocationMatcher::matches(self::one('post_type', '==', 'event'), $ctx));
    }

    private static function grp(string $key, Location $loc): FieldGroup
    {
        return new FieldGroup($key, $key, [], $loc, 'normal', 'default', 0, 'php', null, []);
    }

    public function testGroupIndexBuildsContextFromTheObject(): void
    {
        $GLOBALS['pb_test_posts'] = [42 => ['ID' => 42, 'post_type' => 'event', 'post_status' => 'draft']];
        $GLOBALS['pb_test_terms'] = [7 => ['term_id' => 7, 'taxonomy' => 'category', 'slug' => 'news']];
        $GLOBALS['pb_test_object_terms'] = [42 => ['category' => [7]]];
        $GLOBALS['pb_test_users'] = [3 => ['ID' => 3, 'roles' => ['editor']]];
        $index = new GroupIndex([
            self::grp('news', self::one('post_taxonomy', '==', 'category:news')),
            self::grp('editors', self::one('user_role', '==', 'editor')),
            self::grp('opts', self::one('options_page', '==', 'theme-settings')),
            self::grp('shops', self::one('taxonomy', '==', 'product_cat')),
        ]);
        $keys = static fn(array $groups): array => array_map(static fn(FieldGroup $g): string => $g->key, $groups);

        $this->assertSame(['news'], $keys($index->forRef(ObjectRef::post(42))));
        $this->assertSame(['editors'], $keys($index->forRef(ObjectRef::user(3))));
        $this->assertSame([], $keys($index->forRef(ObjectRef::user(4))));
        $this->assertSame(['opts'], $keys($index->forRef(ObjectRef::option('theme-settings'))));
        $this->assertSame(['shops'], $keys($index->forRef(ObjectRef::term(9, 'product_cat'))));
        $this->assertSame(['editors'], $keys($index->forRef(ObjectRef::user(0), ['user_roles' => ['editor'], 'user_form' => 'register'])));

        // Memoised per request: a later term change is not seen without an explicit context.
        $GLOBALS['pb_test_object_terms'] = [];
        $this->assertSame(['news'], $keys($index->forRef(ObjectRef::post(42))));
        $this->assertSame([], $keys($index->forRef(ObjectRef::post(42), ['post_type' => 'event', 'terms' => []])));
        $this->assertSame(['event'], $index->subtypesFor(self::grp('e', self::one('post_type', '==', 'event')), 'post'));
        $this->assertSame([''], $index->subtypesFor(self::grp('e', self::one('post_type', '!=', 'event')), 'post'));
        $this->assertSame([], $index->subtypesFor(self::grp('e', self::one('post_type', '==', 'event')), 'user'));
    }
}
