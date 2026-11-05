<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Sources;

use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;
use ProtoBlocks\Tests\Modules\Redirects\RedirectsTestCase;

require_once dirname(__DIR__) . '/RedirectsTestCase.php';

/**
 * proto_redirect() / proto_redirect_group() and sealing at init 9.
 */
final class PhpDeclarationsTest extends RedirectsTestCase
{
    public function testProtoRedirectSignatures(): void
    {
        $line = __LINE__ + 1;
        proto_redirect('/old', '/new');
        proto_redirect('/promo', '/sale', 302);
        proto_redirect('/gone', null, 410);
        proto_redirect('^/blog/(.*)$', '/news/$1', ['regex' => true]);
        proto_redirect('/members', '/dashboard', ['when' => ['type' => 'login'], 'else' => '/login', 'from' => '/ignored']);

        $rules = PhpDeclarations::rules();
        self::assertCount(5, $rules);
        self::assertSame(['from' => '/old', 'to' => '/new', 'code' => 301], $rules[0]['rule']);
        self::assertSame(__FILE__ . ':' . $line, $rules[0]['source']);
        self::assertSame(['from' => '/promo', 'to' => '/sale', 'code' => 302], $rules[1]['rule']);
        self::assertSame(['from' => '/gone', 'code' => 410, 'action' => 'error'], $rules[2]['rule']);
        self::assertSame(['from' => '^/blog/(.*)$', 'to' => '/news/$1', 'regex' => true], $rules[3]['rule']);
        self::assertSame('/members', $rules[4]['rule']['from'], 'the positional source wins');
        self::assertSame('/login', $rules[4]['rule']['else']);
    }

    public function testProtoRedirectGroupDerivesTheSlug(): void
    {
        proto_redirect_group('Legacy Site', ['position' => 5, 'rules' => [['from' => '/a', 'to' => '/b']]]);
        proto_redirect_group('Other', ['slug' => 'custom-slug', 'title' => 'Shown title']);

        $groups = PhpDeclarations::groups();
        self::assertSame(['legacy-site', 'custom-slug'], array_keys($groups));
        self::assertSame('Legacy Site', $groups['legacy-site']['data']['title']);
        self::assertSame(5, $groups['legacy-site']['data']['position']);
        self::assertSame('Shown title', $groups['custom-slug']['data']['title']);
        self::assertArrayNotHasKey('slug', $groups['custom-slug']['data']);
        self::assertSame([], PhpDeclarations::issues());
    }

    public function testInvalidReservedAndDuplicateGroups(): void
    {
        proto_redirect_group('Settings', []);
        proto_redirect_group('PHP', []);
        proto_redirect_group('!!!', []);
        proto_redirect_group('Twice', ['position' => 1]);
        proto_redirect_group('Twice', ['position' => 2]);

        self::assertSame(['twice'], array_keys(PhpDeclarations::groups()));
        self::assertSame(2, PhpDeclarations::groups()['twice']['data']['position'], 'the later call wins');
        self::assertSame(
            ['error:invalid_slug', 'error:invalid_slug', 'error:invalid_slug', 'warning:duplicate_php'],
            self::issueCodes(PhpDeclarations::issues())
        );
    }

    public function testCallsAfterTheSealAreDeclaredTooLate(): void
    {
        proto_redirect('/early', '/a');
        PhpDeclarations::seal();
        self::assertTrue(PhpDeclarations::isSealed());
        proto_redirect('/late', '/b');
        proto_redirect_group('Late group', []);

        self::assertCount(1, PhpDeclarations::rules());
        self::assertSame([], PhpDeclarations::groups());
        self::assertSame(['warning:declared_too_late', 'warning:declared_too_late'], self::issueCodes(PhpDeclarations::issues()));
        self::assertStringContainsString('/late', PhpDeclarations::issues()[0]->message);
        self::assertStringContainsString(__FILE__, PhpDeclarations::issues()[0]->source);

        PhpDeclarations::reset();
        self::assertFalse(PhpDeclarations::isSealed());
    }

    public function testBootSealsAtInitNine(): void
    {
        (new RedirectsModule(new \ProtoBlocks\ContentModel\Registry(new \ProtoBlocks\ContentModel\FileLoader(), static fn(): array => [])))->boot();

        $seal = array_values(array_filter(
            $GLOBALS['pb_test_hooks']['init'] ?? [],
            static fn(array $entry): bool => $entry['callback'] === [PhpDeclarations::class, 'seal']
        ));
        self::assertCount(1, $seal);
        self::assertSame(9, $seal[0]['priority']);
    }
}
