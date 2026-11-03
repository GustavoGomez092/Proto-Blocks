<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Validation\ConditionEvaluator;
use ProtoBlocks\Modules\Fields\Validation\ValueValidator;

require_once __DIR__ . '/wp-stubs.php';

/**
 * Runs the shared corpus (also consumed by the client's Jest tests) through
 * ValueValidator, plus focused tests for the evaluator and the regex guards.
 */
final class ValidationCorpusTest extends TestCase
{
    private const CORPUS = __DIR__ . '/../../../fixtures/fields/validation-cases.json';

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $cases = json_decode((string) file_get_contents(self::CORPUS), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $out = [];
        foreach ($cases as $case) {
            $out[$case['id']] = [$case];
        }
        return $out;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $fields = self::fields($case['fields']);
        $choices = isset($case['choices'])
            ? static fn(Field $f): ?array => $case['choices'][$f->name] ?? null
            : null;
        $maxRows = isset($case['maxRows'])
            ? static fn(Field $f): ?int => $case['maxRows'][$f->name] ?? null
            : null;

        $result = ValueValidator::validate($fields, $case['input'], $choices, $maxRows);

        self::assertSame($case['valid'], array_keys($result['valid']), 'valid');
        self::assertEquals($case['errors'], $result['errors'], 'errors');
        self::assertSame($case['hidden'], $result['hidden'], 'hidden');
    }

    public function testCorpusIsExhaustive(): void
    {
        $ids = array_keys(self::cases());
        self::assertGreaterThanOrEqual(30, count($ids));
        foreach ([
            'required-empty-string', 'required-false-truefalse', 'maxlength-120', 'pattern-sku', 'number-min-max',
            'select-unknown-choice', 'select-multiple-partial', 'email-bad', 'url-javascript', 'date-feb-30',
            'repeater-min-rows', 'repeater-over-max-rows', 'repeater-nested-required', 'flexible-unknown-layout',
            'hidden-required-skipped', 'shown-required-enforced', 'contains-operator', 'empty-operator',
            'not-empty-operator', 'pattern-operator', 'invalid-regex-rule', 'missing-field-ref', 'or-groups',
            'and-group', 'nested-condition-in-repeater-row',
        ] as $id) {
            self::assertContains($id, $ids);
        }
    }

    public function testValidValuesComeBackUnchanged(): void
    {
        $fields = self::fields([
            ['key' => 'field_a', 'name' => 'agenda', 'label' => 'A', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_t', 'name' => 'title', 'label' => 'T', 'type' => 'text'],
            ]],
        ]);
        $result = ValueValidator::validate($fields, ['agenda' => [['title' => 'One'], ['title' => 'Two']]]);
        self::assertSame(['agenda' => [['title' => 'One'], ['title' => 'Two']]], $result['valid']);
    }

    public function testRulesDirectly(): void
    {
        self::assertTrue(ConditionEvaluator::rule('==', 'a', 'a'));
        self::assertTrue(ConditionEvaluator::rule('!=', 'a', 'b'));
        self::assertTrue(ConditionEvaluator::rule('==', 5, '5'));
        self::assertTrue(ConditionEvaluator::rule('==contains', ['x', 'y'], 'y'));
        self::assertFalse(ConditionEvaluator::rule('==contains', ['xy'], 'y'));
        self::assertTrue(ConditionEvaluator::rule('==empty', [], null));
        self::assertFalse(ConditionEvaluator::rule('==empty', '0', null));
        self::assertTrue(ConditionEvaluator::rule('!=empty', 0, null));
        self::assertFalse(ConditionEvaluator::rule('nonsense', 'a', 'a'));
    }

    public function testPatternGuards(): void
    {
        self::assertNull(ConditionEvaluator::compilePattern(''));
        self::assertNull(ConditionEvaluator::compilePattern('(unclosed'));
        self::assertNull(ConditionEvaluator::compilePattern(str_repeat('a', ConditionEvaluator::MAX_PATTERN_LENGTH + 1)));
        self::assertNotNull(ConditionEvaluator::compilePattern('^a/b$'));
        self::assertNull(ConditionEvaluator::matches('(unclosed', 'x'));
        self::assertFalse(ConditionEvaluator::matches('^a$', str_repeat('a', ConditionEvaluator::MAX_SUBJECT_LENGTH + 1)));
        // Catastrophic backtracking is cut off by pcre.backtrack_limit and reported as "no answer", not a match.
        $previous = ini_set('pcre.backtrack_limit', '1000');
        try {
            self::assertNull(ConditionEvaluator::matches('^(a+)+$', str_repeat('a', 40) . 'b'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    /** @return array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>} */
    private static function validatePattern(string $pattern, string $value): array
    {
        $fields = self::fields([['key' => 'field_p', 'name' => 'p', 'label' => 'P', 'type' => 'text', 'pattern' => $pattern]]);
        return ValueValidator::validate($fields, ['p' => $value]);
    }

    public function testPatternBacktrackExhaustionIsRejected(): void
    {
        $previous = ini_set('pcre.backtrack_limit', '100');
        try {
            $r = self::validatePattern('^(a+)+$', str_repeat('a', 30) . 'b');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
        self::assertSame([], $r['valid']);
        self::assertSame(['p' => 'The value does not match the required format.'], $r['errors']);
    }

    public function testPatternInvalidUtf8SubjectIsRejected(): void
    {
        $r = self::validatePattern('^ab', "ab\xff");
        self::assertSame([], $r['valid']);
        self::assertSame(['p' => 'The value does not match the required format.'], $r['errors']);
    }

    public function testPatternWithEscapedSlashCompilesAndMatches(): void
    {
        self::assertSame(['p'], array_keys(self::validatePattern('^a\\/b$', 'a/b')['valid']));
        self::assertSame(['p'], array_keys(self::validatePattern('^a/b$', 'a/b')['valid']));
        self::assertNull(ConditionEvaluator::compilePattern('abc\\'));
    }

    public function testUncompilablePatternIsADefinitionError(): void
    {
        $r = self::validatePattern('([a-', 'x');
        self::assertSame([], $r['valid']);
        self::assertSame(['p' => 'Invalid pattern rule.'], $r['errors']);
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<Field>
     */
    private static function fields(array $raw): array
    {
        $group = [
            'key' => 'group_corpus',
            'title' => 'Corpus',
            'fields' => $raw,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ];
        $result = GroupNormalizer::normalize($group, 'file:test');
        self::assertNotNull($result['group'], json_encode($result['report']->errors, JSON_THROW_ON_ERROR));
        return $result['group']->fields;
    }
}
