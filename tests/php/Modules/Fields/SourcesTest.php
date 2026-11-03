<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\DeclarationGroups;
use ProtoBlocks\Modules\Fields\Sources\GroupFiles;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;

require_once __DIR__ . '/wp-stubs.php';

final class SourcesTest extends TestCase
{
    public const THEME = __DIR__ . '/fixtures/theme';

    private string $tmp = '';

    protected function setUp(): void
    {
        PhpGroups::reset();
        $GLOBALS['pb_test_actions'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        $tmp = sys_get_temp_dir() . '/pb-fields-sources-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
    }

    protected function tearDown(): void
    {
        PhpGroups::reset();
        FieldReader::setShared(null);
        $GLOBALS['pb_test_actions'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        self::removeTree($this->tmp);
    }

    /** A test-local JSONC decoder: comments and trailing commas stripped, any root. */
    public static function decode(string $text): array
    {
        $data = json_decode(Jsonc::strip($text), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('not an object or list');
        }
        return $data;
    }

    /** @param list<string> $roots */
    private static function files(array $roots): GroupFiles
    {
        return new GroupFiles(static fn(): array => $roots, [self::class, 'decode']);
    }

    /** @param list<FieldGroup> $groups @return list<string> */
    private static function keys(array $groups): array
    {
        return array_map(static fn(FieldGroup $g): string => $g->key, $groups);
    }

    /** @return list<string> */
    private static function codes(array $entries): array
    {
        return array_map(static fn(array $e): string => $e['code'], $entries);
    }

    private static function group(string $key, string $name = 'subtitle', array $extra = []): array
    {
        return $extra + ['key' => $key, 'title' => 'G ' . $key, 'fields' => [['key' => 'field_' . $name, 'name' => $name, 'label' => 'L', 'type' => 'text']]];
    }

    // ---------------------------------------------------------------- PHP groups

    public function testPhpGroupGetsItsTargetAsLocation(): void
    {
        PhpGroups::add('product', self::group('group_php'), 'functions.php:3');

        $result = PhpGroups::normalized();
        $this->assertCount(1, $result['groups']);
        $group = $result['groups'][0];
        $this->assertSame('php', $group->source);
        $this->assertSame('functions.php:3', $group->path);
        $this->assertTrue($group->location->implied);
        $this->assertSame([[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]], $group->location->or);
    }

    public function testPhpGroupTargets(): void
    {
        $this->assertSame(['taxonomy' => 'genre'], PhpGroups::impliedLocation('taxonomy:genre'));
        $this->assertSame(['options_page' => 'site-settings'], PhpGroups::impliedLocation('options:site-settings'));
        $this->assertNull(PhpGroups::impliedLocation(''));
        $this->assertFalse(PhpGroups::impliedLocation("product\n"));
        $this->assertFalse(PhpGroups::impliedLocation('Product'));
        $this->assertFalse(PhpGroups::impliedLocation('taxonomy:'));

        PhpGroups::add('Not A Slug', self::group('group_bad'), 'functions.php:9');
        $result = PhpGroups::normalized();
        $this->assertSame([], $result['groups']);
        $this->assertSame(['invalid_target'], self::codes($result['reports'][0]['report']->errors));
    }

    public function testPhpGroupWithItsOwnLocation(): void
    {
        PhpGroups::add('', self::group('group_own', 'x', ['location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'all']]]]), 'f.php:1');
        $group = PhpGroups::normalized()['groups'][0];
        $this->assertFalse($group->location->implied);
        $this->assertSame('user_role', $group->location->or[0][0]['param']);
    }

    // ---------------------------------------------------------------- files

    public function testFixtureThemeFilesAreFoundAndDecoded(): void
    {
        $files = self::files([self::THEME]);
        $keys = self::keys($files->all());

        $this->assertSame(['group_6543a1b2c3d4e', 'group_speaker'], $keys);
        $this->assertSame([], $files->errors()->errors);
        $speaker = $files->all()[1];
        $this->assertSame('file:commented', $speaker->source);
        $this->assertSame(realpath(self::THEME) . '/proto-fields/commented/group.jsonc', $speaker->path);
        $this->assertSame(['company', 'born', 'embed'], array_map(static fn($f) => $f->name, $speaker->fields));
        $this->assertSame('date', $speaker->fields[1]->type, 'ACF date_picker maps to date');
        $this->assertSame('code', $speaker->fields[2]->type);
    }

    public function testJsoncWinsOverJson(): void
    {
        $this->put('proto-fields/both/group.json', json_encode(self::group('group_json')));
        $this->put('proto-fields/both/group.jsonc', "// c\n" . json_encode(self::group('group_jsonc')));

        $this->assertSame(['group_jsonc'], self::keys(self::files([$this->tmp . '/theme'])->all()));
    }

    public function testSymlinkOutsideTheRootIsSkipped(): void
    {
        $outside = $this->tmp . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/group.json', (string) json_encode(self::group('group_evil')));
        $this->put('proto-fields/fine/group.json', (string) json_encode(self::group('group_fine')));
        $root = $this->tmp . '/theme';
        symlink($outside, $root . '/proto-fields/evil');
        // A file symlink inside an honest folder, pointing out of the theme.
        mkdir($root . '/proto-fields/linked');
        symlink($outside . '/group.json', $root . '/proto-fields/linked/group.json');

        $files = self::files([$root]);
        $this->assertSame(['group_fine'], self::keys($files->all()));
        $errors = $files->errors()->errors;
        $this->assertSame(['path_outside_root', 'path_outside_root'], self::codes($errors));
        $this->assertStringEndsWith('/proto-fields/evil', $errors[0]['path']);
        $this->assertStringEndsWith('/proto-fields/linked/group.json', $errors[1]['path']);
    }

    public function testSymlinkedProtoFieldsFolderIsSkipped(): void
    {
        $outside = $this->tmp . '/elsewhere';
        mkdir($outside . '/x', 0777, true);
        file_put_contents($outside . '/x/group.json', (string) json_encode(self::group('group_x')));
        mkdir($this->tmp . '/theme');
        symlink($outside, $this->tmp . '/theme/proto-fields');

        $files = self::files([$this->tmp . '/theme']);
        $this->assertSame([], $files->all());
        $this->assertSame(['path_outside_root'], self::codes($files->errors()->errors));
    }

    public function testInvalidFolderSlugSkipped(): void
    {
        $this->put('proto-fields/Evil Name/group.json', (string) json_encode(self::group('group_a')));
        $this->put('proto-fields/x..y/group.json', (string) json_encode(self::group('group_b')));
        $this->put('proto-fields/' . str_repeat('a', 33) . '/group.json', (string) json_encode(self::group('group_c')));
        $this->put('proto-fields/ok/group.json', (string) json_encode(self::group('group_ok')));

        $files = self::files([$this->tmp . '/theme']);
        $this->assertSame(['group_ok'], self::keys($files->all()));
        $this->assertSame(['invalid_slug', 'invalid_slug', 'invalid_slug'], self::codes($files->errors()->errors));
        $paths = array_map(static fn(array $e): string => basename($e['path']), $files->errors()->errors);
        $this->assertContains('Evil Name', $paths);
        $this->assertContains('x..y', $paths);
    }

    public function testOversizeFileSkipped(): void
    {
        $group = self::group('group_big', 'big', ['padding' => str_repeat('x', 300 * 1024)]);
        $this->put('proto-fields/big/group.json', (string) json_encode($group));

        $files = self::files([$this->tmp . '/theme']);
        $this->assertSame([], $files->all());
        $this->assertSame(['file_too_large'], self::codes($files->errors()->errors));
    }

    public function testDecodeErrorIsReported(): void
    {
        $this->put('proto-fields/broken/group.jsonc', '{ "key": ');
        $files = self::files([$this->tmp . '/theme']);
        $this->assertSame([], $files->all());
        $this->assertSame(['invalid_jsonc'], self::codes($files->errors()->errors));
    }

    public function testChildRootWinsOnTheSameSlug(): void
    {
        $this->put('proto-fields/shared/group.json', (string) json_encode(self::group('group_parent')));
        $this->put('proto-fields/parent-only/group.json', (string) json_encode(self::group('group_p_only')));
        $child = $this->tmp . '/child';
        mkdir($child . '/proto-fields/shared', 0777, true);
        file_put_contents($child . '/proto-fields/shared/group.json', (string) json_encode(self::group('group_child')));

        $files = self::files([$this->tmp . '/theme', $child]);
        $this->assertSame(['group_p_only', 'group_child'], self::keys($files->all()));
        $this->assertStringStartsWith($child, $files->files()['shared']['path']);
    }

    public function testFileIsNeverIncluded(): void
    {
        $this->put('proto-fields/php/group.json', '<?php $GLOBALS["pb_included"] = true; ?>');
        $files = self::files([$this->tmp . '/theme']);
        $this->assertSame([], $files->all());
        $this->assertArrayNotHasKey('pb_included', $GLOBALS);
        $this->assertSame(['invalid_jsonc'], self::codes($files->errors()->errors));
    }

    // ---------------------------------------------------------------- declarations

    public function testDeclarationGroupsImplyTheirLocation(): void
    {
        $declarations = new DeclarationGroups(static fn(): array => [
            (object) ['kind' => 'type', 'slug' => 'event', 'origin' => 'file', 'source' => '/t/proto-types/event/type.jsonc', 'data' => ['fields' => [self::group('group_event')]]],
            (object) ['kind' => 'taxonomy', 'slug' => 'genre', 'origin' => 'php', 'source' => 'functions.php:4', 'data' => ['fields' => [self::group('group_genre')]]],
            (object) ['kind' => 'type', 'slug' => 'plain', 'origin' => 'file', 'source' => '/t/x', 'data' => []],
            (object) ['kind' => 'options', 'slug' => 'site', 'origin' => 'file', 'source' => '/t/y', 'data' => ['fields' => [self::group('group_opt')]]],
        ]);

        $groups = $declarations->all();
        $this->assertSame(['group_event', 'group_genre'], self::keys($groups));
        $this->assertSame([[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], $groups[0]->location->or);
        $this->assertSame('/t/proto-types/event/type.jsonc', $groups[0]->path);
        $this->assertSame('type:event', $groups[0]->source);
        $this->assertSame([[['param' => 'taxonomy', 'operator' => '==', 'value' => 'genre']]], $groups[1]->location->or);
        $this->assertNull($groups[1]->path, 'a PHP declaration has no file path');
        $this->assertSame('taxonomy:genre', $groups[1]->source);
    }

    public function testDeclarationGroupWithErrorsIsDropped(): void
    {
        $declarations = new DeclarationGroups(static fn(): array => [
            (object) ['kind' => 'type', 'slug' => 'event', 'origin' => 'file', 'source' => '/t', 'data' => ['fields' => [['title' => 'No key', 'fields' => []], self::group('group_ok')]]],
        ]);
        $this->assertSame(['group_ok'], self::keys($declarations->all()));
    }

    // ---------------------------------------------------------------- merge order

    public function testOrderIsPhpThenDeclarationsThenFiles(): void
    {
        PhpGroups::add('event', self::group('group_php'), 'functions.php:3');
        $module = new FieldsModule(
            static fn(): array => [self::THEME],
            [self::class, 'decode'],
            static fn(): array => [(object) ['kind' => 'type', 'slug' => 'event', 'origin' => 'file', 'source' => '/t/type.jsonc', 'data' => ['fields' => [self::group('group_decl', 'other')]]]],
        );
        do_action('init');

        $this->assertSame(['group_php', 'group_decl', 'group_6543a1b2c3d4e', 'group_speaker'], self::keys($module->index()->all()));
    }

    public function testFileBeatsPhpOnSameKey(): void
    {
        PhpGroups::add('event', self::group('group_6543a1b2c3d4e', 'php_only'), 'functions.php:7');
        $module = new FieldsModule(static fn(): array => [self::THEME], [self::class, 'decode']);
        do_action('init');

        $groups = $module->index()->all();
        $this->assertSame(['group_6543a1b2c3d4e', 'group_speaker'], self::keys($groups));
        $this->assertSame('file:acf-export', $groups[0]->source);

        $warnings = array_values(array_filter($module->report()->warnings, static fn(array $w): bool => $w['code'] === 'duplicate_group_key'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('functions.php:7', $warnings[0]['message']);
        $this->assertStringContainsString('proto-fields/acf-export/group.json', $warnings[0]['message']);
    }

    public function testDeclarationFileBeatsPhpGroupAndProtoFieldsFileBeatsDeclaration(): void
    {
        PhpGroups::add('event', self::group('group_same', 'from_php'), 'functions.php:1');
        $this->put('proto-fields/same/group.json', (string) json_encode(self::group('group_same', 'from_file', ['location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]]])));
        $module = new FieldsModule(
            fn(): array => [$this->tmp . '/theme'],
            [self::class, 'decode'],
            static fn(): array => [(object) ['kind' => 'type', 'slug' => 'event', 'origin' => 'file', 'source' => '/t/type.jsonc', 'data' => ['fields' => [self::group('group_same', 'from_decl')]]]],
        );
        do_action('init');

        $groups = $module->index()->all();
        $this->assertCount(1, $groups);
        $this->assertSame('from_file', $groups[0]->fields[0]->name);
        $this->assertCount(2, array_filter($module->report()->warnings, static fn(array $w): bool => $w['code'] === 'duplicate_group_key'));
    }

    public function testIndexIsNotBuiltBeforeInit(): void
    {
        PhpGroups::add('event', self::group('group_php'), 'functions.php:3');
        $module = new FieldsModule();

        $this->assertSame([], $module->index()->all(), 'nothing before init');
        do_action('init');
        $this->assertSame(['group_php'], self::keys($module->index()->all()), 'built on the first use after init');
    }

    // ---------------------------------------------------------------- helpers

    private function put(string $relative, string $text): void
    {
        $path = $this->tmp . '/theme/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !file_exists($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
