<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Sources;

use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Tests\Modules\Redirects\RedirectsTestCase;

require_once dirname(__DIR__) . '/RedirectsTestCase.php';

/**
 * Discovery of proto-redirects/<slug>/redirects.jsonc and settings.jsonc.
 */
final class GroupFilesTest extends RedirectsTestCase
{
    private function files(): GroupFiles
    {
        return new GroupFiles(fn(): array => [$this->parent, $this->child]);
    }

    public function testLoadsJsoncAndJsonGroupsInSlugOrder(): void
    {
        $this->put($this->parent, 'proto-redirects/zeta/redirects.json', '{"rules": []}');
        $this->put($this->parent, 'proto-redirects/alpha/redirects.jsonc', "// comment\n{\"title\": \"Alpha\", \"rules\": []}");

        $groups = $this->files()->groups();

        self::assertSame(['alpha', 'zeta'], array_keys($groups));
        self::assertSame('Alpha', $groups['alpha']['data']['title']);
        self::assertSame($this->parent . '/proto-redirects/alpha/redirects.jsonc', $groups['alpha']['path']);
        self::assertSame($this->parent, $groups['alpha']['root']);
        self::assertSame([], $this->files()->issues());
    }

    public function testDefaultRootsAreTheThemeRoots(): void
    {
        $this->put($this->child, 'proto-redirects/a/redirects.jsonc', '{}');

        self::assertSame(['a'], array_keys((new GroupFiles())->groups()));
    }

    public function testJsoncWinsOverJsonWithBothExtensionsWarning(): void
    {
        $this->put($this->parent, 'proto-redirects/a/redirects.jsonc', '{"title": "From jsonc"}');
        $this->put($this->parent, 'proto-redirects/a/redirects.json', '{"title": "From json"}');
        $files = $this->files();

        self::assertSame('From jsonc', $files->groups()['a']['data']['title']);
        self::assertSame(['warning:both_extensions'], self::issueCodes($files->issues()));
        self::assertSame('a', $files->issues()[0]->slug);
        self::assertSame('redirects', $files->issues()[0]->kind);
    }

    public function testChildThemeOverridesParentGroup(): void
    {
        $this->put($this->parent, 'proto-redirects/legacy/redirects.jsonc', '{"title": "Parent"}');
        $this->put($this->parent, 'proto-redirects/parent-only/redirects.jsonc', '{"title": "P"}');
        $this->put($this->child, 'proto-redirects/legacy/redirects.jsonc', '{"title": "Child"}');

        $groups = $this->files()->groups();

        self::assertSame('Child', $groups['legacy']['data']['title']);
        self::assertSame($this->child, $groups['legacy']['root']);
        self::assertArrayHasKey('parent-only', $groups);
    }

    public function testBrokenJsoncSkipsTheGroupOthersStillLoad(): void
    {
        $this->put($this->parent, 'proto-redirects/good/redirects.jsonc', '{"rules": []}');
        $this->put($this->parent, 'proto-redirects/bad/redirects.jsonc', '{"rules": [ }');
        $files = $this->files();

        self::assertSame(['good'], array_keys($files->groups()));
        self::assertSame(['error:invalid_jsonc'], self::issueCodes($files->issues()));
        self::assertSame('bad', $files->issues()[0]->slug);
    }

    public function testBrokenChildFileDoesNotLetTheParentBackIn(): void
    {
        $this->put($this->parent, 'proto-redirects/legacy/redirects.jsonc', '{"title": "Parent"}');
        $this->put($this->child, 'proto-redirects/legacy/redirects.jsonc', '{"title": ');
        $files = $this->files();

        self::assertSame([], $files->groups());
        self::assertSame(['error:invalid_jsonc', 'error:broken_override'], self::issueCodes($files->issues()));
    }

    public function testNonObjectFileIsRefused(): void
    {
        $this->put($this->parent, 'proto-redirects/list/redirects.json', '[{"from": "/a"}]');
        $this->put($this->parent, 'proto-redirects/scalar/redirects.json', '"x"');
        $files = $this->files();

        self::assertSame([], $files->groups());
        self::assertSame(['error:invalid_jsonc', 'error:invalid_jsonc'], self::issueCodes($files->issues()));
    }

    public function testFileCapIs256Kb(): void
    {
        $atCap = '{"rules": []}' . str_repeat(' ', GroupFiles::MAX_BYTES - 13);
        self::assertSame(262144, strlen($atCap));
        $this->put($this->parent, 'proto-redirects/at-cap/redirects.jsonc', $atCap);
        $this->put($this->parent, 'proto-redirects/over-cap/redirects.jsonc', $atCap . ' ');
        $files = $this->files();

        self::assertSame(['at-cap'], array_keys($files->groups()));
        self::assertSame(['error:file_too_large'], self::issueCodes($files->issues()));
        self::assertStringContainsString('Split the group', $files->issues()[0]->message);
    }

    public function testPhpFolderIsReservedForThePhpDeclaredGroup(): void
    {
        $this->put($this->child, 'proto-redirects/php/redirects.jsonc', '{"rules": [{"from": "/a", "to": "/b"}]}');
        $files = $this->files();

        self::assertSame([], $files->groups());
        self::assertSame(['error:invalid_slug'], self::issueCodes($files->issues()));
        self::assertStringContainsString('proto_redirect()', $files->issues()[0]->message);
    }

    public function testInvalidAndReservedSlugFoldersAreSkipped(): void
    {
        $this->put($this->parent, 'proto-redirects/Bad Name/redirects.jsonc', '{}');
        $this->put($this->parent, 'proto-redirects/settings/redirects.jsonc', '{}');
        $this->put($this->parent, 'proto-redirects/' . str_repeat('a', 61) . '/redirects.jsonc', '{}');
        $this->put($this->parent, 'proto-redirects/' . str_repeat('b', 60) . '/redirects.jsonc', '{}');
        $this->put($this->parent, 'proto-redirects/empty/notes.txt', 'x');
        $files = $this->files();

        self::assertSame([str_repeat('b', 60)], array_keys($files->groups()));
        self::assertSame(['error:invalid_slug', 'error:invalid_slug', 'error:invalid_slug'], self::issueCodes($files->issues()));
        $messages = implode(' ', array_map(static fn($i): string => $i->message, $files->issues()));
        self::assertStringContainsString('reserved', $messages);
    }

    public function testSymlinkOutOfTheThemeIsRefused(): void
    {
        $outside = $this->tmp . '/outside';
        $this->put($outside, 'redirects.jsonc', '{"rules": []}');
        mkdir($this->parent . '/proto-redirects');
        symlink($outside, $this->parent . '/proto-redirects/escape');

        $files = $this->files();

        self::assertSame([], $files->groups());
        self::assertSame(['error:path_outside_root'], self::issueCodes($files->issues()));
    }

    public function testSettingsFileChildWinsAndBrokenChildFallsBackToDefaults(): void
    {
        $this->put($this->parent, 'proto-redirects/settings.jsonc', '{"cache": 24}');
        self::assertSame(['cache' => 24], $this->files()->settingsFile()['data']);

        $this->put($this->child, 'proto-redirects/settings.json', '{"cache": 0}');
        $files = $this->files();
        self::assertSame(['cache' => 0], $files->settingsFile()['data']);
        self::assertSame($this->child . '/proto-redirects/settings.json', $files->settingsFile()['path']);

        file_put_contents($this->child . '/proto-redirects/settings.json', '{"cache": ');
        $files = $this->files();
        self::assertNull($files->settingsFile(), 'never the parent\'s file');
        self::assertSame(['error:invalid_jsonc'], self::issueCodes($files->issues()));
        self::assertSame('redirect-settings', $files->issues()[0]->kind);
        self::assertSame('settings', $files->issues()[0]->slug);
    }

    public function testSettingsFileAloneOpensNoGroupFile(): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', '{"cache": 24}');
        for ($i = 0; $i < 50; $i++) {
            $this->put($this->child, 'proto-redirects/g' . $i . '/redirects.jsonc', '{"rules": []}');
        }
        $decoded = 0;
        $files = new GroupFiles(fn(): array => [$this->parent, $this->child], static function (string $text) use (&$decoded) {
            $decoded++;
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        });

        self::assertSame(['cache' => 24], $files->settingsFile()['data']);
        self::assertSame(['cache' => 24], $files->settingsFile()['data']);
        self::assertSame(1, $decoded, 'only settings.jsonc was decoded');

        self::assertCount(50, $files->groups());
        self::assertSame(52, $decoded, 'the full load reads every group (and the settings file again)');
        self::assertSame(['cache' => 24], $files->settingsFile()['data']);
        self::assertSame(52, $decoded);
    }

    public function testSettingsOnlyReadKeepsFindingsForTheFullLoad(): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', '{}');
        $this->put($this->child, 'proto-redirects/settings.json', '{}');
        $files = $this->files();

        self::assertSame([], $files->settingsFile()['data']);
        self::assertSame(['warning:both_extensions'], self::issueCodes($files->issues()), 'reported once, by the full load');
    }

    public function testSettingsOnlyReadFailsClosedOnABrokenChild(): void
    {
        $this->put($this->parent, 'proto-redirects/settings.jsonc', '{"cache": 24}');
        $this->put($this->child, 'proto-redirects/settings.jsonc', '{"cache": ');

        self::assertNull($this->files()->settingsFile());
    }

    public function testSettingsBothExtensionsAndPathsList(): void
    {
        $a = $this->put($this->parent, 'proto-redirects/settings.jsonc', '{}');
        $this->put($this->parent, 'proto-redirects/settings.json', '{}');
        $b = $this->put($this->parent, 'proto-redirects/g/redirects.jsonc', '{}');
        $files = $this->files();

        self::assertSame(['warning:both_extensions'], self::issueCodes($files->issues()));
        self::assertSame([$b, $a], $files->paths());
    }

    public function testFilesAreDataNeverExecuted(): void
    {
        $this->put($this->parent, 'proto-redirects/exec/redirects.jsonc', '<?php $GLOBALS["pb_redirects_executed"] = true; ?>');
        $files = $this->files();

        self::assertSame([], $files->groups());
        self::assertArrayNotHasKey('pb_redirects_executed', $GLOBALS);
        self::assertSame(['error:invalid_jsonc'], self::issueCodes($files->issues()));
    }
}
