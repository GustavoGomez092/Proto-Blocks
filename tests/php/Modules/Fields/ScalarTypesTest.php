<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

require_once __DIR__ . '/wp-stubs.php';

final class ScalarTypesTest extends TestCase
{
    private static function field(string $type, array $settings = []): Field
    {
        return new Field('field_x', 'x', 'X', $type, $type, false, null, '', '', [], [], [], $settings);
    }

    /** @return array<string, array{0: string, 1: array, 2: mixed, 3: mixed, 4: mixed}> */
    public static function scalarCases(): array
    {
        $choices = ['choices' => ['a' => 'A', 'b' => 'B']];

        return [
            'text strips tags, escapes stored' => ['text', [], '<b>x</b><script>a</script>', 'x', '&lt;b&gt;x&lt;/b&gt;&lt;script&gt;a&lt;/script&gt;'],
            'text array is empty' => ['text', [], ['x'], '', ''],
            'password' => ['password', [], ' p<i>w</i> ', 'pw', ' p&lt;i&gt;w&lt;/i&gt; '],
            'textarea keeps newlines' => ['textarea', [], "a\nb<script>x</script>", "a\nb", "a\nb&lt;script&gt;x&lt;/script&gt;"],
            'textarea br' => ['textarea', ['new_lines' => 'br'], "a\nb", "a\nb", "a<br />\nb"],
            'number int' => ['number', ['step' => 1], '12', 12, 12],
            'number float' => ['number', ['step' => 0.5], '1.5', 1.5, 1.5],
            'number empty' => ['number', ['step' => 1], '', null, null],
            'number junk' => ['number', [], 'abc', null, null],
            'number array' => ['number', [], [1], null, null],
            'number bool' => ['number', [], true, null, null],
            'number overflow float' => ['number', [], '1e999', null, null],
            'number integral step overflow' => ['number', ['step' => 1], '1e308', null, null],
            'number fractional step keeps big' => ['number', ['step' => 0.5], '1e308', 1e308, 1e308],
            'number integral step rounds' => ['number', ['step' => 1], '1.6', 2, 2],
            'number no step int' => ['number', [], '7', 7, 7],
            'number no step float' => ['number', [], '7.25', 7.25, 7.25],
            'email' => ['email', [], 'a@b.co ', 'a@b.co', 'a@b.co '],
            'email invalid' => ['email', [], 'nope', '', 'nope'],
            'url javascript' => ['url', [], 'javascript:alert(1)', '', ''],
            'url https' => ['url', [], 'https://x.test/a', 'https://x.test/a', 'https://x.test/a'],
            'wysiwyg' => ['wysiwyg', [], '<p onclick="x">a</p><script>b</script>', '<p>a</p>b', '<p>a</p>b'],
            'select multiple' => ['select', $choices + ['multiple' => 1], ['a', 'zz'], ['a'], ['a', 'zz']],
            'select multiple scalar' => ['select', $choices + ['multiple' => 1], 'b', ['b'], ['b']],
            'select multiple dedupes' => ['select', $choices + ['multiple' => 1], ['a', 'a'], ['a'], ['a', 'a']],
            'select single' => ['select', $choices, 'b', 'b', 'b'],
            'select single invalid' => ['select', $choices, 'zz', '', 'zz'],
            'select single array' => ['select', $choices, ['a'], '', ''],
            'radio' => ['radio', $choices, 'a', 'a', 'a'],
            'select numeric keys' => ['select', ['choices' => [1 => 'One', 2 => 'Two']], '2', '2', '2'],
            'select provider' => ['select', ['optionsSource' => 'wp:posts'], '42', '42', '42'],
            'select provider empty' => ['select', ['optionsSource' => 'wp:posts'], '', '', ''],
            'select no choices refuses all' => ['select', [], 'a', '', 'a'],
            'true_false 1' => ['true_false', [], '1', true, true],
            'true_false empty' => ['true_false', [], '', false, false],
            'true_false bool' => ['true_false', [], true, true, true],
            'true_false array' => ['true_false', [], [1], false, false],
            'color ok' => ['color', [], '#FFF', '#FFF', '#FFF'],
            'color injection' => ['color', [], 'red;}', '', ''],
            'date ok' => ['date', [], '2026-02-28', '2026-02-28', '2026-02-28'],
            'date rollover' => ['date', [], '2026-02-30', '', '2026-02-30'],
            'date junk' => ['date', [], '<i>2026-02-28', '', '&lt;i&gt;2026-02-28'],
            'time ok' => ['time', [], '23:59:59', '23:59:59', '23:59:59'],
            'time bad' => ['time', [], '25:00:00', '', '25:00:00'],
            'datetime ok' => ['datetime', [], '2026-02-28 10:00:00', '2026-02-28 10:00:00', '2026-02-28 10:00:00'],
            'datetime date only' => ['datetime', [], '2026-02-28', '', '2026-02-28'],
            'link' => [
                'link', [],
                ['url' => 'javascript:x', 'title' => '<i>t</i>', 'target' => '_top'],
                ['url' => '', 'title' => 't', 'target' => ''],
                ['url' => '', 'title' => '&lt;i&gt;t&lt;/i&gt;', 'target' => ''],
            ],
            'link string' => [
                'link', [], 'https://x.test',
                ['url' => 'https://x.test', 'title' => '', 'target' => ''],
                ['url' => 'https://x.test', 'title' => '', 'target' => ''],
            ],
            'link extra keys dropped' => [
                'link', [],
                ['url' => 'https://x.test', 'title' => 'T', 'target' => '_blank', 'rel' => 'opener', 'onclick' => 'x'],
                ['url' => 'https://x.test', 'title' => 'T', 'target' => '_blank'],
                ['url' => 'https://x.test', 'title' => 'T', 'target' => '_blank', 'rel' => 'noopener noreferrer'],
            ],
            'code refused' => ['code', [], '<script>x</script>', null, '<script>x</script>'],
        ];
    }

    #[DataProvider('scalarCases')]
    public function testSanitizeAndEscape(string $type, array $settings, mixed $input, mixed $sanitized, mixed $escaped): void
    {
        $field = self::field($type, $settings);
        $handler = TypeRegistry::withCoreTypes()->for($field);

        $this->assertSame($sanitized, $handler->sanitize($input, $field), 'sanitize');
        // escape() is given the raw input as if it were already stored, which
        // is the worst case: a value written by a trusted user or straight to the database.
        $this->assertSame($escaped, $handler->escape($input, $field), 'escape');
    }

    public function testRestSchemaTypes(): void
    {
        $registry = TypeRegistry::withCoreTypes();
        $schema = static fn(Field $f): array => $registry->for($f)->restSchema($f);

        $this->assertSame(['type' => ['number', 'null']], $schema(self::field('number')));
        $this->assertSame(['type' => ['number', 'null']], $schema(self::field('number', ['step' => 0.5])));
        $this->assertSame(['type' => ['integer', 'null']], $schema(self::field('number', ['step' => 1])));
        $this->assertSame(['type' => 'string'], $schema(self::field('text')));
        $this->assertSame(
            ['anyOf' => [['type' => 'string', 'format' => 'email'], ['type' => 'string', 'maxLength' => 0]]],
            $schema(self::field('email'))
        );
        $this->assertSame(
            ['anyOf' => [['type' => 'string', 'format' => 'uri'], ['type' => 'string', 'maxLength' => 0]]],
            $schema(self::field('url'))
        );
        $this->assertSame(['type' => 'boolean'], $schema(self::field('true_false')));
        // Dates carry no `format` (core rejects '' for it); sanitize() enforces the shape.
        foreach (['date', 'time', 'datetime'] as $type) {
            $this->assertSame(['type' => 'string'], $schema(self::field($type)), $type);
        }
        $this->assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']]],
            $schema(self::field('select', ['multiple' => 1, 'choices' => ['a' => 'A', 'b' => 'B']]))
        );
        $this->assertSame(
            ['type' => 'string', 'enum' => ['', 'a', 'b']],
            $schema(self::field('radio', ['choices' => ['a' => 'A', 'b' => 'B']]))
        );
        $required = new Field('field_x', 'x', 'X', 'select', 'select', true, null, '', '', [], [], [], ['choices' => ['a' => 'A']]);
        $this->assertSame(['type' => 'string', 'enum' => ['a']], $registry->for($required)->restSchema($required));
        $this->assertSame(
            ['type' => 'string', 'enum' => ['', 'a', 'b']],
            $schema(self::field('select', ['choices' => ['a', 'b']]))
        );
        $this->assertSame(['type' => 'string'], $schema(self::field('select', ['optionsSource' => 'wp:posts'])));
        $this->assertSame(
            ['type' => 'array', 'items' => ['type' => 'string']],
            $schema(self::field('select', ['multiple' => true, 'optionsSource' => 'wp:posts', 'choices' => ['a' => 'A']]))
        );

        $link = $schema(self::field('link'));
        $this->assertSame('object', $link['type']);
        $this->assertSame(['url', 'title', 'target'], array_keys($link['properties']));
        $this->assertFalse($link['additionalProperties']);
    }

    public function testLinkEscapeAddsRelForBlank(): void
    {
        $field = self::field('link');
        $handler = TypeRegistry::withCoreTypes()->for($field);

        $blank = $handler->escape(['url' => 'https://x.test', 'title' => 'T', 'target' => '_blank'], $field);
        $this->assertSame('noopener noreferrer', $blank['rel']);

        $self = $handler->escape(['url' => 'https://x.test', 'title' => 'T', 'target' => ''], $field);
        $this->assertArrayNotHasKey('rel', $self);

        // A stored target that is not exactly _blank never reaches the output.
        $odd = $handler->escape(['url' => 'https://x.test', 'target' => '" onclick="x'], $field);
        $this->assertSame('', $odd['target']);
        $this->assertArrayNotHasKey('rel', $odd);
    }

    public function testLinkEscapeNeutralisesStoredJavascriptUrl(): void
    {
        $field = self::field('link');
        $handler = TypeRegistry::withCoreTypes()->for($field);

        $this->assertSame('', $handler->escape(['url' => 'javascript:alert(1)'], $field)['url']);
    }

    public function testWysiwygEscapeStripsStoredScript(): void
    {
        $field = self::field('wysiwyg');
        $handler = TypeRegistry::withCoreTypes()->for($field);

        $this->assertSame('<p>a</p>b', $handler->escape('<p onclick="x">a</p><script>b</script>', $field));
    }

    public function testNormalizeIsShapeOnly(): void
    {
        $registry = TypeRegistry::withCoreTypes();
        $n = static fn(string $type, mixed $v, array $s = []): mixed => $registry->for(self::field($type, $s))->normalize($v, self::field($type, $s));

        // No content filtering: a trusted user's markup survives.
        $this->assertSame('<script>x</script>', $n('code', '<script>x</script>'));
        $this->assertSame('<b>x</b>', $n('text', '<b>x</b>'));
        $this->assertSame('javascript:x', $n('url', 'javascript:x'));
        // Shapes are coerced.
        $this->assertSame('', $n('text', ['a']));
        $this->assertSame(['a', '1'], $n('select', ['a', 1, null, ''], ['multiple' => 1]));
        $this->assertSame(['url' => 'u', 'title' => '', 'target' => ''], $n('link', ['url' => 'u', 'x' => 'y']));
        $this->assertTrue($n('true_false', 'true'));
        $this->assertNull($n('number', 'x'));
    }

    public function testEmptyValuesAndStores(): void
    {
        $registry = TypeRegistry::withCoreTypes();
        $empty = static fn(string $type, array $s = []): mixed => $registry->for(self::field($type, $s))->emptyValue(self::field($type, $s));

        $this->assertSame('', $empty('text'));
        $this->assertNull($empty('number'));
        $this->assertFalse($empty('true_false'));
        $this->assertSame([], $empty('select', ['multiple' => 1]));
        $this->assertSame('', $empty('select'));
        $this->assertSame(['url' => '', 'title' => '', 'target' => ''], $empty('link'));

        foreach (['tab', 'accordion', 'message'] as $type) {
            $this->assertFalse($registry->for(self::field($type))->stores(), $type);
        }
        foreach (['text', 'code', 'link', 'select', 'wysiwyg'] as $type) {
            $this->assertTrue($registry->for(self::field($type))->stores(), $type);
        }
    }

    public function testOnlyCodeRequiresUnfilteredHtml(): void
    {
        $registry = TypeRegistry::withCoreTypes();
        foreach (['text', 'password', 'textarea', 'number', 'email', 'url', 'wysiwyg', 'select', 'radio', 'true_false', 'color', 'date', 'time', 'datetime', 'link', 'tab', 'accordion', 'message'] as $type) {
            $this->assertFalse($registry->for(self::field($type))->requiresUnfilteredHtml(), $type);
        }
        $code = self::field('code');
        $handler = $registry->for($code);
        $this->assertTrue($handler->requiresUnfilteredHtml());
        $this->assertNull($handler->sanitize('<script>x</script>', $code));
        // number's null is "empty", not "refuse": the flag is what tells them apart.
        $number = self::field('number');
        $this->assertNull($registry->for($number)->sanitize('', $number));
        $this->assertFalse($registry->for($number)->requiresUnfilteredHtml());
    }

    public function testChoiceListChoicesAndDedupe(): void
    {
        $field = self::field('select', ['multiple' => 1, 'choices' => ['x', 'y']]);
        $handler = TypeRegistry::withCoreTypes()->for($field);

        $this->assertSame(['x', 'y'], $handler->sanitize(['x', 'q', 'y', 'x'], $field));
    }

    public function testTrueFalseNanIsFalse(): void
    {
        $field = self::field('true_false');
        $this->assertFalse(TypeRegistry::withCoreTypes()->for($field)->sanitize(NAN, $field));
    }

    public function testRegistryRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TypeRegistry::withCoreTypes()->for(self::field('unknown'));
    }

    public function testRegistryRejectsDuplicateNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TypeRegistry([new \ProtoBlocks\Modules\Fields\Types\TextType(), new \ProtoBlocks\Modules\Fields\Types\TextType()]);
    }
}
