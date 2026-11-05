<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Registry;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Definition\Settings;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;
use ProtoBlocks\Tests\Modules\Redirects\RedirectsTestCase;

require_once dirname(__DIR__) . '/RedirectsTestCase.php';

/**
 * Merging, precedence, ordering and issues of the redirects registry.
 */
final class RegistryTest extends RedirectsTestCase
{
    private function registry(): Registry
    {
        return new Registry(fn(): GroupFiles => new GroupFiles(fn(): array => [$this->parent, $this->child]));
    }

    /** @return list<string> "group/from" of rules() */
    private static function order(Registry $registry): array
    {
        return array_map(static fn(array $r): string => $r['group'] . ':' . $r['from'], $registry->rules());
    }

    public function testFileGroupIsNormalisedWithRegistryKeys(): void
    {
        $path = $this->put($this->parent, 'proto-redirects/legacy-site/redirects.jsonc', self::groupText([
            ['from' => '/about-us', 'to' => '/about'],
        ], ['title' => 'Legacy site']));
        $registry = $this->registry();

        $group = $registry->group('legacy-site');
        self::assertNotNull($group);
        self::assertSame('Legacy site', $group['title']);
        self::assertTrue($group['enabled']);
        self::assertSame(10, $group['position']);
        self::assertSame(['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'], $group['defaults']);
        self::assertSame('file', $group['origin']);
        self::assertSame($path, $group['source']);
        self::assertSame($this->parent, $group['root']);
        self::assertNull($group['double']);

        $rule = $registry->rules()[0];
        self::assertSame('legacy-site', $rule['group']);
        self::assertSame('file', $rule['origin']);
        self::assertSame($path, $rule['source']);
        self::assertSame(0, $rule['index']);
        self::assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/D', $rule['id']);
        self::assertSame([], $registry->issues());
        self::assertNull($registry->group('missing'));
    }

    public function testTitleDefaultsToTheSlug(): void
    {
        $this->put($this->parent, 'proto-redirects/untitled/redirects.jsonc', '{}');

        self::assertSame('untitled', $this->registry()->group('untitled')['title']);
    }

    public function testEvaluationOrderPositionThenSlugThenFileOrder(): void
    {
        $this->put($this->parent, 'proto-redirects/b-group/redirects.jsonc', self::groupText([['from' => '/b1', 'to' => '/x'], ['from' => '/b2', 'to' => '/x']], ['position' => 5]));
        $this->put($this->parent, 'proto-redirects/a-group/redirects.jsonc', self::groupText([['from' => '/a1', 'to' => '/x']], ['position' => 5]));
        $this->put($this->parent, 'proto-redirects/first/redirects.jsonc', self::groupText([['from' => '/f', 'to' => '/x']], ['position' => -1]));
        $this->put($this->parent, 'proto-redirects/default/redirects.jsonc', self::groupText([['from' => '/d', 'to' => '/x']]));
        proto_redirect('/p', '/x');

        self::assertSame(['first:/f', 'a-group:/a1', 'b-group:/b1', 'b-group:/b2', 'default:/d', 'php:/p'], self::order($this->registry()));
    }

    public function testDisabledGroupContributesNothingDisabledRulesAreIncluded(): void
    {
        $this->put($this->parent, 'proto-redirects/off/redirects.jsonc', self::groupText([['from' => '/a', 'to' => '/b', 'enabled' => true]], ['enabled' => false]));
        $this->put($this->parent, 'proto-redirects/on/redirects.jsonc', self::groupText([['from' => '/c', 'to' => '/d', 'enabled' => false], ['from' => '/e', 'to' => '/f']]));
        $registry = $this->registry();

        self::assertCount(2, $registry->groups(), 'groups() keeps the disabled group');
        self::assertSame(['on:/c', 'on:/e'], self::order($registry));
        self::assertFalse($registry->rules()[0]['enabled']);
        self::assertSame(['/e'], array_column($registry->enabledRules(), 'from'));
    }

    public function testRuleLevelErrorDropsOnlyThatRule(): void
    {
        $this->put($this->parent, 'proto-redirects/mixed/redirects.jsonc', self::groupText([
            ['from' => '/ok', 'to' => '/fine'],
            ['from' => '^/(', 'regex' => true, 'to' => '/x'],
            ['from' => '/ok2', 'to' => '/fine2'],
        ]));
        $registry = $this->registry();

        self::assertSame(['/ok', '/ok2'], array_column($registry->rules(), 'from'));
        self::assertSame([0, 2], array_column($registry->rules(), 'index'));
        self::assertSame(['error:invalid_regex'], self::issueCodes($registry->issues()));
        self::assertSame('mixed', $registry->issues()[0]->slug);
        self::assertStringStartsWith('/rules/1/from: ', $registry->issues()[0]->message);
    }

    public function testGroupLevelErrorAndBrokenJsoncSkipTheGroupOthersStillRun(): void
    {
        $this->put($this->parent, 'proto-redirects/schema-bad/redirects.jsonc', '{"colour": "red", "rules": [{"from": "/a", "to": "/b"}]}');
        $this->put($this->parent, 'proto-redirects/broken/redirects.jsonc', '{"rules": [');
        $this->put($this->parent, 'proto-redirects/good/redirects.jsonc', self::groupText([['from' => '/g', 'to' => '/h']]));
        $registry = $this->registry();

        self::assertSame(['good'], array_column($registry->groups(), 'slug'));
        self::assertSame(['/g'], array_column($registry->rules(), 'from'));
        $codes = self::issueCodes($registry->issues());
        self::assertContains('error:invalid_jsonc', $codes);
        self::assertContains('error:schema_invalid', $codes);
    }

    public function testChildThemeOverridesAParentGroup(): void
    {
        $this->put($this->parent, 'proto-redirects/legacy/redirects.jsonc', self::groupText([['from' => '/parent', 'to' => '/x']]));
        $this->put($this->child, 'proto-redirects/legacy/redirects.jsonc', self::groupText([['from' => '/child', 'to' => '/x']]));

        self::assertSame(['legacy:/child'], self::order($this->registry()));
    }

    public function testFileGroupReplacesThePhpGroupWhole(): void
    {
        $path = $this->put($this->parent, 'proto-redirects/legacy-site/redirects.jsonc', self::groupText([['from' => '/file', 'to' => '/x']]));
        $line = __LINE__ + 1;
        proto_redirect_group('Legacy site', ['rules' => [['from' => '/php-only', 'to' => '/y']]]);
        proto_redirect_group('PHP group', ['rules' => [['from' => '/kept', 'to' => '/z']]]);
        $registry = $this->registry();

        self::assertSame(['legacy-site:/file', 'php-group:/kept'], self::order($registry));
        self::assertSame(['file' => $path, 'php' => __FILE__ . ':' . $line], $registry->group('legacy-site')['double']);
        self::assertSame('php', $registry->group('php-group')['origin']);
        self::assertNull($registry->group('php-group')['root']);
        self::assertSame(['warning:double_declaration'], self::issueCodes($registry->issues()));
    }

    public function testPhpRuleDeclaringAFileRuleIsDroppedNamingTheCallSite(): void
    {
        $this->put($this->parent, 'proto-redirects/legacy/redirects.jsonc', self::groupText([
            ['id' => 'r_0000aaaa', 'from' => '/About/', 'to' => '/about-us'],
            ['from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dash'],
        ]));
        $line = __LINE__ + 1;
        proto_redirect('/about', '/elsewhere');
        proto_redirect('/members', '/other');                                       // different condition: kept
        proto_redirect('/members', '/x', ['when' => ['type' => 'login']]);          // same: dropped
        $registry = $this->registry();

        self::assertSame(['legacy:/About/', 'legacy:/members', 'php:/members'], self::order($registry));
        $issues = $registry->issues();
        self::assertSame(['warning:double_declaration', 'warning:double_declaration'], self::issueCodes($issues));
        self::assertSame(__FILE__ . ':' . $line, $issues[0]->source);
        self::assertStringContainsString(__FILE__ . ':' . $line, $issues[0]->message);
        self::assertSame('legacy', $issues[0]->slug);
        self::assertSame('r_0000aaaa', $issues[0]->key);
        // The surviving PHP rule keeps its own call site.
        self::assertSame(__FILE__ . ':' . ($line + 1), $registry->rules()[2]['source']);
        self::assertSame('php', $registry->rules()[2]['origin']);
    }

    public function testVirtualPhpGroup(): void
    {
        proto_redirect('/a', '/b');
        $group = $this->registry()->group('php');

        self::assertSame('Declared in PHP', $group['title']);
        self::assertSame(100, $group['position']);
        self::assertSame('php', $group['origin']);
        self::assertSame([], $this->registry()->issues());
    }

    public function testInvalidPhpRuleIsReportedAtItsCallSite(): void
    {
        $line = __LINE__ + 1;
        proto_redirect('/a', 'javascript:alert(1)');
        $registry = $this->registry();

        self::assertSame([], $registry->rules());
        self::assertSame(['error:invalid_target'], self::issueCodes($registry->issues()));
        self::assertSame(__FILE__ . ':' . $line, $registry->issues()[0]->source);
    }

    public function testBrokenFileAlsoDropsThePhpGroupOfItsSlug(): void
    {
        $this->put($this->parent, 'proto-redirects/legacy/redirects.jsonc', '{"rules": [');
        proto_redirect_group('Legacy', ['rules' => [['from' => '/a', 'to' => '/b']]]);
        $registry = $this->registry();

        self::assertSame([], $registry->rules());
        self::assertSame(['error:invalid_jsonc', 'error:broken_override'], self::issueCodes($registry->issues()));
    }

    public function testSealedDeclarationsAndDeclarationIssuesSurface(): void
    {
        PhpDeclarations::seal();
        proto_redirect('/late', '/x');

        $registry = $this->registry();
        self::assertSame([], $registry->rules());
        self::assertSame(['warning:declared_too_late'], self::issueCodes($registry->issues()));
    }

    public function testChainWarningAcrossGroups(): void
    {
        $this->put($this->parent, 'proto-redirects/one/redirects.jsonc', self::groupText([['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]));
        $this->put($this->parent, 'proto-redirects/two/redirects.jsonc', self::groupText([['id' => 'r_00000002', 'from' => '/b', 'to' => '/c']]));
        $issues = $this->registry()->issues();

        self::assertSame(['warning:redirect_chain'], self::issueCodes($issues));
        self::assertSame('one', $issues[0]->slug);
        self::assertSame('r_00000001', $issues[0]->key);
        self::assertStringContainsString('r_00000002 (two)', $issues[0]->message);

        // No warning when the second group is disabled.
        $this->put($this->parent, 'proto-redirects/two/redirects.jsonc', self::groupText([['from' => '/b', 'to' => '/c']], ['enabled' => false]));
        self::assertSame([], $this->registry()->issues());
    }

    public function testSettingsAreMergedAndValidated(): void
    {
        $this->put($this->parent, 'proto-redirects/settings.jsonc', '{"defaults": {"ignoreCase": false}, "cache": 48, "log": {"redirects": 30}}');
        $this->put($this->parent, 'proto-redirects/g/redirects.jsonc', self::groupText([['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => '/d', 'ignoreCase' => true]], ['defaults' => ['query' => 'ignore']]));
        $registry = $this->registry();

        $settings = $registry->settings();
        self::assertSame(1, $settings['cache'], 'clamped to the default');
        self::assertSame(30, $settings['log']['redirects']);
        self::assertSame(['error:schema_invalid'], self::issueCodes($registry->issues()));
        self::assertSame('redirect-settings', $registry->issues()[0]->kind);
        self::assertSame($this->parent . '/proto-redirects/settings.jsonc', $registry->settingsPath());

        // Three levels: site default, group default, rule flag.
        self::assertSame(['ignoreCase' => false, 'ignoreTrailingSlash' => true, 'query' => 'ignore'], $registry->group('g')['defaults']);
        [$a, $c] = $registry->rules();
        self::assertFalse($a['ignoreCase']);
        self::assertSame('ignore', $a['query']);
        self::assertTrue($c['ignoreCase']);
    }

    public function testNoSettingsFileGivesDefaults(): void
    {
        self::assertSame(Settings::defaults(), $this->registry()->settings());
        self::assertNull($this->registry()->settingsPath());
    }

    public function testInvalidateRereadsAndFilesHashFollowsContent(): void
    {
        $path = $this->put($this->parent, 'proto-redirects/g/redirects.jsonc', self::groupText([['from' => '/a', 'to' => '/b']]));
        $registry = $this->registry();
        $hash = $registry->filesHash();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $hash);
        self::assertSame($hash, $this->registry()->filesHash(), 'stable');
        self::assertCount(1, $registry->rules());

        file_put_contents($path, self::groupText([['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => '/d']]));
        self::assertCount(1, $registry->rules(), 'built once');
        $registry->invalidate();
        self::assertCount(2, $registry->rules());
        self::assertNotSame($hash, $registry->filesHash());

        $this->put($this->parent, 'proto-redirects/settings.jsonc', '{}');
        $before = $registry->filesHash();
        $registry->invalidate();
        self::assertNotSame($before, $registry->filesHash(), 'the settings file counts');
    }

    public function testFromArraysAcceptsRawAndNormalisedGroups(): void
    {
        $registry = Registry::fromArrays(
            [['slug' => 'files', 'position' => 1, 'rules' => [['from' => '/a', 'to' => '/b'], ['from' => '/m', 'when' => ['type' => 'login'], 'to' => '/d']]]],
            [['slug' => 'php', 'title' => 'Declared in PHP', 'position' => 100, 'rules' => [['from' => '/a', 'to' => '/c', 'source' => 'functions.php:12'], ['from' => '/z', 'to' => '/y', 'source' => 'functions.php:13']]]],
            ['cache' => 24]
        );

        self::assertSame(['files:/a', 'files:/m', 'php:/z'], self::order($registry));
        self::assertSame('functions.php:13', $registry->rules()[2]['source']);
        self::assertSame(24, $registry->settings()['cache']);
        self::assertSame(['warning:double_declaration'], self::issueCodes($registry->issues()));
        self::assertSame('functions.php:12', $registry->issues()[0]->source);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $registry->filesHash());

        // A normalised Group (as groups() returns it) round-trips.
        [$files, $php] = $registry->groups();
        $again = Registry::fromArrays([$files], [$php], ['cache' => 24]);
        $strip = static fn(array $rules): array => array_map(static fn(array $r): array => array_diff_key($r, ['index' => 1]), $rules);
        self::assertSame($strip($registry->rules()), $strip($again->rules()));
        self::assertSame([], $again->issues());
    }

    public function testCoexistencePause(): void
    {
        $active = static fn(): bool => true;
        $inactive = static fn(): bool => false;

        self::assertTrue(Coexistence::paused(Settings::defaults(), $active));
        self::assertFalse(Coexistence::paused(Settings::merge(['coexist' => true]), $active));
        self::assertFalse(Coexistence::paused(Settings::defaults(), $inactive));
        self::assertFalse(Coexistence::redirectionActive(), 'Redirection is not loaded in the test run');
        self::assertFalse(Coexistence::paused(Settings::defaults()));
    }

    public function testModuleExposesRegistrySettingsAndIssues(): void
    {
        $this->put($this->child, 'proto-redirects/g/redirects.jsonc', self::groupText([['from' => '^/(', 'regex' => true, 'to' => '/x']]));
        $module = new RedirectsModule(new \ProtoBlocks\ContentModel\Registry(new \ProtoBlocks\ContentModel\FileLoader(), static fn(): array => []));

        self::assertSame($module->registry(), $module->registry());
        self::assertSame(Settings::defaults(), $module->settings());
        self::assertFalse($module->paused());
        self::assertSame(['error:invalid_regex'], self::issueCodes($module->issues()));
        self::assertContainsOnlyInstancesOf(Issue::class, $module->issues());
        self::assertSame('redirects', $module->id());
    }
}
