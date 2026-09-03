# Multiselect Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `multiselect` control type to Proto-Blocks that stores an ordered array of option keys, reusing `select`'s existing options contract.

**Architecture:** `multiselect` registers as a core control with `data_type: 'array'`. The editor renders core's `FormTokenField` for add/remove/search plus a dnd-kit sortable list for ordering. All label↔key translation lives in a **pure, dependency-free module** so it can be unit-tested; the React component is a thin shell around it. No server-side options code changes — the existing `/proto-blocks/v1/controls/options` endpoint and its providers are reused as-is.

**Tech Stack:** PHP 8.0+, PHPUnit (`vendor/bin/phpunit`), TypeScript, React via `@wordpress/element`, `@wordpress/components` (webpack external), `@dnd-kit/{core,sortable,utilities}` (already installed), Jest via `wp-scripts test-unit-js`.

**Spec:** `docs/superpowers/specs/2026-09-02-multiselect-control-design.md`

## Global Constraints

- **Branch:** all plugin work lands on `feat/multiselect-control`. Do **not** push to `main` mid-plan — `.github/workflows/build-and-release.yml` runs on every push to `main` and would cut a release from a half-finished feature.
- **No new npm or composer dependencies.** `@dnd-kit/core@^6.1.0`, `@dnd-kit/sortable@^8.0.0` and `@dnd-kit/utilities@^3.2.2` are already in `dependencies`.
- **No component-mounting tests.** `@testing-library/react` is not installed and `@wordpress/components` is a webpack external (`wp.components` at runtime), so it cannot be imported under Jest. Test pure modules; verify the React shell manually in the editor. Do **not** add a testing-library dependency — that is out of scope.
- **Config surface is exactly `select`'s:** `options`, `optionsSource`, `sourceArgs`, `label`, `help`, `default`, `conditions`. No `min`, `max` or `sortable` keys. Drag-reordering is unconditional.
- **Stored value:** `string[]` of option keys, order-significant. Keys stay strings even when numeric.
- **Commit style:** conventional commits, no AI/tooling attribution of any kind in the message body or footer.
- **PHP tests run with:** `vendor/bin/phpunit`. PHP unit tests are plain PHPUnit against `tests/php/bootstrap.php` stubs — **not** a WordPress test suite. `sanitize_text_field()` there is a stub that only trims.

---

### Task 1: Sanitise array-typed control values

`Registry::sanitize()`'s `match` has no `array` arm, so its `default` returns the value untouched. Every array-typed control — this one and any future one — is currently unsanitised. Fix the registry rather than giving the new control a bespoke callback.

**Files:**
- Create: `tests/php/Controls/RegistrySanitizeArrayTest.php`
- Modify: `includes/Controls/Registry.php` (the `match` inside `sanitize()`)

**Interfaces:**
- Consumes: nothing.
- Produces: `Registry::sanitize('<array-typed type>', mixed $value, array $config = []): array` — returns a re-indexed list of unique, non-empty, `sanitize_text_field`-ed scalar strings; `[]` for any non-array input.

- [ ] **Step 1: Write the failing test**

Create `tests/php/Controls/RegistrySanitizeArrayTest.php`:

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Controls;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Controls\Registry;

final class RegistrySanitizeArrayTest extends TestCase
{
    private function registry(): Registry
    {
        $registry = new Registry();
        $registry->register('multiselect', [
            'data_type' => 'array',
            'default'   => [],
        ]);

        return $registry;
    }

    public function test_non_array_becomes_empty_array(): void
    {
        $this->assertSame([], $this->registry()->sanitize('multiselect', 'nope'));
        $this->assertSame([], $this->registry()->sanitize('multiselect', null));
        $this->assertSame([], $this->registry()->sanitize('multiselect', 42));
    }

    public function test_clean_list_passes_through_order_intact(): void
    {
        $this->assertSame(
            ['18279', '18277', '18282'],
            $this->registry()->sanitize('multiselect', ['18279', '18277', '18282'])
        );
    }

    public function test_drops_non_scalars_and_empties_and_collapses_duplicates(): void
    {
        $this->assertSame(
            ['12', '9'],
            $this->registry()->sanitize('multiselect', ['12', ['nested'], '', '9', '12', null])
        );
    }

    public function test_reindexes_so_the_result_is_a_json_list_not_an_object(): void
    {
        $result = $this->registry()->sanitize('multiselect', [3 => 'a', 7 => 'b']);

        $this->assertSame(['a', 'b'], $result);
        $this->assertSame('["a","b"]', json_encode($result));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter RegistrySanitizeArrayTest`
Expected: FAIL. `test_non_array_becomes_empty_array` fails first — `sanitize()` returns the string `'nope'` unchanged because the `match` falls through to `default => $value`.

- [ ] **Step 3: Add the `array` arm**

In `includes/Controls/Registry.php`, inside `sanitize()`, the `match` currently reads:

```php
        return match ($controlConfig['data_type'] ?? 'string') {
            'boolean' => (bool) $value,
            'number' => is_numeric($value) ? (float) $value : 0,
            'integer' => (int) $value,
            'string' => sanitize_text_field((string) $value),
            'object' => is_array($value) ? $value : [],
            default => $value,
        };
```

Add an `'array'` arm before `'object'`:

```php
        return match ($controlConfig['data_type'] ?? 'string') {
            'boolean' => (bool) $value,
            'number' => is_numeric($value) ? (float) $value : 0,
            'integer' => (int) $value,
            'string' => sanitize_text_field((string) $value),
            // A list of scalar keys. Re-indexed with array_values() so it
            // serialises as a JSON array rather than an object -- a block
            // attribute typed `array` that arrives as `{"1":"a"}` is rejected
            // by the editor's attribute validation and the control renders
            // empty. Duplicates are collapsed because the UI cannot produce
            // them, so a repeat means a hand-edited payload.
            'array' => is_array($value)
                ? array_values(array_unique(array_filter(
                    array_map(
                        static fn($item) => sanitize_text_field((string) $item),
                        array_filter($value, 'is_scalar')
                    ),
                    static fn($item) => $item !== ''
                )))
                : [],
            'object' => is_array($value) ? $value : [],
            default => $value,
        };
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter RegistrySanitizeArrayTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Run the whole PHP suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, no failures. (`failOnWarning="true"` is set in `phpunit.xml.dist`, so warnings fail the run too.)

- [ ] **Step 6: Commit**

```bash
git add tests/php/Controls/RegistrySanitizeArrayTest.php includes/Controls/Registry.php
git commit -m "fix(controls): sanitise array-typed control values

Registry::sanitize()'s match had no array arm, so its default returned
the value untouched and any array-typed control was unsanitised. Adds
one that filters to unique non-empty scalar strings and re-indexes, so
the result serialises as a JSON list rather than an object."
```

---

### Task 2: Register `multiselect` and teach the validator about it

**Files:**
- Create: `tests/php/Schema/ControlTypeValidationTest.php`
- Modify: `includes/Core/Plugin.php` (`registerCoreControlTypes()`)
- Modify: `includes/Schema/SchemaValidator.php` (`VALID_CONTROL_TYPES`, `validateControl()`)

**Interfaces:**
- Consumes: `Registry::sanitize()`'s `'array'` arm from Task 1.
- Produces: control type `multiselect` (`data_type` `array`, default `[]`), so `Registry::buildAttribute()` emits `['type' => 'array', 'default' => []]`. `SchemaValidator::validate()` accepts `multiselect` and errors when it has neither `options` nor `optionsSource`.

**Note on `validate()`:** it **throws** `\InvalidArgumentException` when `$this->errors` is non-empty — it does not return `false`. `getErrors()` is populated before the throw, so assert via `expectException` or catch and inspect.

- [ ] **Step 1: Write the failing test**

Create `tests/php/Schema/ControlTypeValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

final class ControlTypeValidationTest extends TestCase
{
    /** @param array<string, mixed> $controls */
    private function schema(array $controls): array
    {
        return [
            'name'         => 'proto-blocks/demo',
            'protoBlocks'  => ['controls' => $controls],
        ];
    }

    public function test_multiselect_with_an_options_source_is_valid_and_silent(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'picks' => [
                'type'          => 'multiselect',
                'label'         => 'Picks',
                'optionsSource' => 'wp:posts',
                'sourceArgs'    => ['post_type' => 'product'],
            ],
        ])));

        $this->assertSame([], $validator->getErrors());
        $this->assertSame([], $validator->getWarnings());
    }

    public function test_multiselect_with_static_options_is_valid(): void
    {
        $validator = new SchemaValidator();

        $this->assertTrue($validator->validate($this->schema([
            'picks' => [
                'type'    => 'multiselect',
                'label'   => 'Picks',
                'options' => [['key' => 'a', 'label' => 'A']],
            ],
        ])));

        $this->assertSame([], $validator->getWarnings());
    }

    public function test_multiselect_without_options_or_source_is_a_hard_error(): void
    {
        $validator = new SchemaValidator();

        try {
            $validator->validate($this->schema([
                'picks' => ['type' => 'multiselect', 'label' => 'Picks'],
            ]));
            $this->fail('Expected InvalidArgumentException for a multiselect with no options.');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(1, $validator->getErrors());
            $this->assertStringContainsString('picks', $validator->getErrors()[0]);
        }
    }

    /**
     * VALID_CONTROL_TYPES listed 7 of the 12 types registerCoreControlTypes()
     * registers, so these five warned spuriously on every validate.
     *
     * @dataProvider previouslyUnlistedTypes
     */
    public function test_registered_control_types_do_not_warn(string $type): void
    {
        $validator = new SchemaValidator();
        $validator->validate($this->schema([
            'thing' => ['type' => $type, 'label' => 'Thing', 'options' => [['key' => 'a', 'label' => 'A']]],
        ]));

        $this->assertSame([], $validator->getWarnings(), "Control type '{$type}' should be recognised");
    }

    /** @return array<string, array{string}> */
    public static function previouslyUnlistedTypes(): array
    {
        return [
            'textarea'      => ['textarea'],
            'checkbox'      => ['checkbox'],
            'color-palette' => ['color-palette'],
            'radio'         => ['radio'],
            'video'         => ['video'],
        ];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ControlTypeValidationTest`
Expected: FAIL. `test_multiselect_with_an_options_source_is_valid_and_silent` fails on the warnings assertion (`Control "picks" uses unknown type "multiselect"`), `test_multiselect_without_options_or_source_is_a_hard_error` fails because no exception is thrown, and all five `previouslyUnlistedTypes` rows fail.

- [ ] **Step 3: Register the control type**

In `includes/Core/Plugin.php`, in `registerCoreControlTypes()`, add after the `select` registration:

```php
        $registry->register('select', [
            'data_type' => 'string',
            'default' => '',
        ]);

        // Same config contract as `select` -- static `options` or a server
        // `optionsSource` -- but stores an ordered list of keys rather than one.
        $registry->register('multiselect', [
            'data_type' => 'array',
            'default' => [],
        ]);
```

- [ ] **Step 4: Update the validator**

In `includes/Schema/SchemaValidator.php`, replace the `VALID_CONTROL_TYPES` constant:

```php
    private const VALID_CONTROL_TYPES = [
        'text',
        'textarea',
        'select',
        'multiselect',
        'toggle',
        'checkbox',
        'range',
        'number',
        'color',
        'color-palette',
        'radio',
        'image',
        'video',
    ];
```

Then in `validateControl()`, replace the select-only options guard:

```php
        // Select controls must have static options OR a dynamic options source
        if ($type === 'select' && empty($control['options']) && empty($control['optionsSource'])) {
```

with one covering both option-driven types:

```php
        // Select and multiselect must have static options OR a dynamic source
        if (
            in_array($type, ['select', 'multiselect'], true)
            && empty($control['options'])
            && empty($control['optionsSource'])
        ) {
```

and update the message to name the type rather than hard-coding "Select":

```php
            $this->errors[] = sprintf(
                'Control "%s" of type "%s" must have options or an optionsSource defined',
                $name,
                $type
            );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ControlTypeValidationTest`
Expected: PASS, 7 tests (3 explicit + 5 data rows − shared counting: 3 named tests plus 5 provider rows = 8 assertions-bearing cases; any all-green result is correct).

Then the full suite: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/php/Schema/ControlTypeValidationTest.php includes/Core/Plugin.php includes/Schema/SchemaValidator.php
git commit -m "feat(controls): register the multiselect control type

Registers multiselect as an array-typed core control taking exactly the
same options/optionsSource contract as select, and extends the schema
validator's options guard to cover both.

Also fills in VALID_CONTROL_TYPES, which listed 7 of the 12 registered
types -- textarea, checkbox, color-palette, radio and video warned as
unknown on every validate."
```

---

### Task 3: Pure label↔key module

`FormTokenField` is a control over label **strings**; the attribute stores **keys**. Two posts can share a title, so the mapping must be explicit, collision-aware, and total in both directions. All of it lives here, with no React and no `@wordpress/components` import, so Jest can test it.

**Files:**
- Create: `src/editor/controls/multiselect-options.ts`
- Create: `src/editor/controls/__tests__/multiselect-options.test.ts`

**Interfaces:**
- Consumes: `SelectOption` (`{ value: string; label: string }`) exported from `src/editor/controls/options-source.ts`.
- Produces:
  - `mergeOptions(previous: SelectOption[], incoming: SelectOption[]): SelectOption[]`
  - `buildLabelIndex(options: SelectOption[]): LabelIndex` where `LabelIndex = { labelByKey: Map<string,string>; keyByLabel: Map<string,string> }`
  - `keysToLabels(keys: string[], index: LabelIndex): string[]`
  - `labelsToKeys(labels: string[], index: LabelIndex, fallbackKeys?: string[]): string[]`
  - `suggestionsFor(index: LabelIndex, selectedKeys: string[]): string[]`

- [ ] **Step 1: Write the failing test**

Create `src/editor/controls/__tests__/multiselect-options.test.ts`:

```ts
import {
    buildLabelIndex,
    keysToLabels,
    labelsToKeys,
    mergeOptions,
    suggestionsFor,
} from '../multiselect-options';

const opts = (...pairs: Array<[string, string]>) =>
    pairs.map(([value, label]) => ({ value, label }));

describe('mergeOptions', () => {
    it('keeps previous order and appends genuinely new options', () => {
        const result = mergeOptions(opts(['1', 'One'], ['2', 'Two']), opts(['3', 'Three']));

        expect(result).toEqual(opts(['1', 'One'], ['2', 'Two'], ['3', 'Three']));
    });

    it('lets incoming options overwrite a stale label for the same key', () => {
        const result = mergeOptions(opts(['1', 'Old name']), opts(['1', 'New name']));

        expect(result).toEqual(opts(['1', 'New name']));
    });

    it('does not move a key that reappears in the incoming page', () => {
        const result = mergeOptions(opts(['1', 'One'], ['2', 'Two']), opts(['2', 'Two']));

        expect(result.map((o) => o.value)).toEqual(['1', '2']);
    });
});

describe('buildLabelIndex', () => {
    it('uses the bare label when it is unique', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(index.labelByKey.get('1')).toBe('One');
        expect(index.keyByLabel.get('One')).toBe('1');
    });

    it('disambiguates every member of a colliding label with its key', () => {
        const index = buildLabelIndex(opts(['18277', 'Half Size Oven'], ['18282', 'Half Size Oven']));

        expect(index.labelByKey.get('18277')).toBe('Half Size Oven (#18277)');
        expect(index.labelByKey.get('18282')).toBe('Half Size Oven (#18282)');
        expect(index.keyByLabel.get('Half Size Oven (#18282)')).toBe('18282');
        expect(index.keyByLabel.has('Half Size Oven')).toBe(false);
    });

    it('leaves an unrelated unique label alone when another label collides', () => {
        const index = buildLabelIndex(opts(['1', 'Dup'], ['2', 'Dup'], ['3', 'Unique']));

        expect(index.labelByKey.get('3')).toBe('Unique');
    });
});

describe('keysToLabels', () => {
    it('maps known keys to their display labels', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(keysToLabels(['2', '1'], index)).toEqual(['Two', 'One']);
    });

    it('falls back to the bare key when the option is gone', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(keysToLabels(['1', '999'], index)).toEqual(['One', '999']);
    });
});

describe('labelsToKeys', () => {
    it('maps display labels back to keys, preserving order', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two']));

        expect(labelsToKeys(['Two', 'One'], index)).toEqual(['2', '1']);
    });

    it('round-trips a disambiguated label to the bare key', () => {
        const index = buildLabelIndex(opts(['18277', 'Dup'], ['18282', 'Dup']));

        expect(labelsToKeys(['Dup (#18282)'], index)).toEqual(['18282']);
    });

    it('drops free text the author typed that matches no option', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', 'not a product'], index)).toEqual(['1']);
    });

    it('keeps an unresolvable key that keysToLabels rendered bare', () => {
        // The round-trip that would otherwise silently drop a selection whose
        // option is not in the current result page.
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', '999'], index, ['1', '999'])).toEqual(['1', '999']);
    });

    it('collapses duplicates', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(labelsToKeys(['One', 'One'], index)).toEqual(['1']);
    });
});

describe('suggestionsFor', () => {
    it('omits already-selected keys', () => {
        const index = buildLabelIndex(opts(['1', 'One'], ['2', 'Two'], ['3', 'Three']));

        expect(suggestionsFor(index, ['2'])).toEqual(['One', 'Three']);
    });

    it('returns everything when nothing is selected', () => {
        const index = buildLabelIndex(opts(['1', 'One']));

        expect(suggestionsFor(index, [])).toEqual(['One']);
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- multiselect-options`
Expected: FAIL — `Cannot find module '../multiselect-options'`.

- [ ] **Step 3: Write the module**

Create `src/editor/controls/multiselect-options.ts`:

```ts
/**
 * Label <-> key translation for the multiselect control.
 *
 * FormTokenField is a control over label *strings*, but the block attribute
 * stores option *keys*. Two options can legitimately share a label (two
 * products with the same title), so a naive Map<label, key> would silently
 * resolve one of them to the other's key. Everything here is pure so it can be
 * unit-tested without mounting the component -- @wordpress/components is a
 * webpack external and cannot be imported under Jest.
 */

import { SelectOption } from './options-source';

export interface LabelIndex {
    /** Key -> the label shown in the UI (disambiguated where labels collide). */
    labelByKey: Map<string, string>;
    /** The reverse of labelByKey. Total, because display labels are unique. */
    keyByLabel: Map<string, string>;
}

/**
 * Union of two option pages, keyed by option key.
 *
 * Map.set on an existing key overwrites the value but keeps the original
 * insertion position, which is exactly what we want: a fresher label wins,
 * but an option does not jump around the suggestion list because it happened
 * to appear in a later search response.
 */
export function mergeOptions(
    previous: SelectOption[],
    incoming: SelectOption[]
): SelectOption[] {
    const byKey = new Map<string, SelectOption>();

    previous.forEach((option) => byKey.set(option.value, option));
    incoming.forEach((option) => byKey.set(option.value, option));

    return Array.from(byKey.values());
}

/**
 * Build the two-way index, suffixing colliding labels with their key.
 *
 * The suffix is display-only -- the stored value is always the bare key.
 */
export function buildLabelIndex(options: SelectOption[]): LabelIndex {
    const occurrences = new Map<string, number>();
    options.forEach((option) => {
        occurrences.set(option.label, (occurrences.get(option.label) ?? 0) + 1);
    });

    const labelByKey = new Map<string, string>();
    const keyByLabel = new Map<string, string>();

    options.forEach((option) => {
        const display =
            (occurrences.get(option.label) ?? 0) > 1
                ? `${option.label} (#${option.value})`
                : option.label;

        labelByKey.set(option.value, display);
        keyByLabel.set(display, option.value);
    });

    return { labelByKey, keyByLabel };
}

/**
 * Render selected keys as tokens.
 *
 * An unknown key renders as itself rather than disappearing: a selection whose
 * option is not in the current search page, or whose post was deleted, must
 * still be visible and removable.
 */
export function keysToLabels(keys: string[], index: LabelIndex): string[] {
    return keys.map((key) => index.labelByKey.get(key) ?? key);
}

/**
 * Translate the token list FormTokenField hands back into stored keys.
 *
 * `fallbackKeys` closes the round-trip opened by keysToLabels: a bare key
 * rendered as its own token comes back as that same string, and without the
 * fallback it would match no label and be silently dropped -- deleting the
 * author's selection the moment they touched an unrelated token.
 *
 * Free text matching neither is discarded: FormTokenField accepts arbitrary
 * input, and there is no key to store for it.
 */
export function labelsToKeys(
    labels: string[],
    index: LabelIndex,
    fallbackKeys: string[] = []
): string[] {
    const fallback = new Set(fallbackKeys);
    const keys: string[] = [];

    labels.forEach((label) => {
        const key =
            index.keyByLabel.get(label) ?? (fallback.has(label) ? label : undefined);

        if (key !== undefined && !keys.includes(key)) {
            keys.push(key);
        }
    });

    return keys;
}

/** Display labels for everything not already chosen. */
export function suggestionsFor(index: LabelIndex, selectedKeys: string[]): string[] {
    const selected = new Set(selectedKeys);
    const suggestions: string[] = [];

    index.labelByKey.forEach((label, key) => {
        if (!selected.has(key)) {
            suggestions.push(label);
        }
    });

    return suggestions;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- multiselect-options`
Expected: PASS, 15 tests.

- [ ] **Step 5: Commit**

```bash
git add src/editor/controls/multiselect-options.ts src/editor/controls/__tests__/multiselect-options.test.ts
git commit -m "feat(controls): label/key mapping for the multiselect control

FormTokenField works in label strings while the attribute stores keys,
and two options can share a label. Adds a pure two-way index that
disambiguates collisions with the key, keeps unresolvable keys visible
as bare tokens, and round-trips them without dropping the selection."
```

---

### Task 4: The `MultiSelectControl` component

**Files:**
- Create: `src/editor/controls/MultiSelectControl.tsx`
- Modify: `src/editor/controls/render.tsx` (import + a `case 'multiselect'`)
- Modify: `assets/css/editor.css` (append the sortable-list styles)

**Interfaces:**
- Consumes: `fetchControlOptions(source, args)` and `SelectOption` from `./options-source`; `mergeOptions`, `buildLabelIndex`, `keysToLabels`, `labelsToKeys`, `suggestionsFor`, `LabelIndex` from `./multiselect-options`; `useDebouncedCallback(callback, delay)` from `../utils/debounce`.
- Produces: `<MultiSelectControl label value options source sourceArgs onChange />` where `value: string[]` and `onChange: (keys: string[]) => void`.

There is no unit test for this task — see Global Constraints. It is verified by build plus manual editor checks in Step 4.

- [ ] **Step 1: Write the component**

Create `src/editor/controls/MultiSelectControl.tsx`:

```tsx
/**
 * A control that stores an ordered list of option keys.
 *
 * Selection is core's FormTokenField (the control used for post categories and
 * tags, so it is already familiar and keyboard accessible). Ordering is a
 * dnd-kit sortable list beneath it, matching RepeaterField's drag pattern.
 *
 * Options come from the same pipeline `select` uses: static `options`, or a
 * server `optionsSource`. Because the server clamps per_page to 200 and real
 * catalogues exceed that, typing in the field re-queries the server rather
 * than filtering only what happened to load on mount.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { FormTokenField, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { fetchControlOptions, SelectOption } from './options-source';
import {
    buildLabelIndex,
    keysToLabels,
    labelsToKeys,
    mergeOptions,
    suggestionsFor,
} from './multiselect-options';
import { useDebouncedCallback } from '../utils/debounce';

interface MultiSelectControlProps {
    label: string;
    value: string[];
    options?: Array<{ key: string; label: string }>;
    source?: string;
    sourceArgs?: Record<string, unknown>;
    onChange: (value: string[]) => void;
}

interface SortableTokenProps {
    id: string;
    label: string;
    onRemove: (id: string) => void;
}

function SortableToken({ id, label, onRemove }: SortableTokenProps): JSX.Element {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            className={`proto-blocks-multiselect__item${
                isDragging ? ' is-dragging' : ''
            }`}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
        >
            <button
                type="button"
                className="proto-blocks-multiselect__handle"
                aria-label={__('Reorder', 'proto-blocks')}
                {...attributes}
                {...listeners}
            >
                ⠿
            </button>
            <span className="proto-blocks-multiselect__label">{label}</span>
            <button
                type="button"
                className="components-button is-link is-destructive is-small"
                onClick={() => onRemove(id)}
            >
                {__('Remove', 'proto-blocks')}
            </button>
        </li>
    );
}

export function MultiSelectControl({
    label,
    value,
    options: staticOptions,
    source,
    sourceArgs = {},
    onChange,
}: MultiSelectControlProps): JSX.Element {
    const selected = useMemo(() => (Array.isArray(value) ? value : []), [value]);

    const [known, setKnown] = useState<SelectOption[]>(() =>
        (staticOptions ?? []).map((o) => ({ value: o.key, label: o.label }))
    );
    const [loading, setLoading] = useState<boolean>(Boolean(source));
    const [error, setError] = useState<string | null>(null);

    // Serialised so the effect re-runs only when the args actually change --
    // sourceArgs is rebuilt each render from static block.json config.
    const argsKey = JSON.stringify(sourceArgs);
    const requestId = useRef(0);

    const load = useCallback(
        (search: string) => {
            if (!source) {
                return;
            }

            const id = ++requestId.current;
            setLoading(true);
            setError(null);

            fetchControlOptions(source, {
                ...JSON.parse(argsKey),
                ...(search ? { search } : {}),
            })
                .then((incoming) => {
                    // Ignore a response that a newer keystroke has superseded.
                    if (id !== requestId.current) {
                        return;
                    }
                    // Accumulate rather than replace: a token for a key that
                    // has fallen out of the current page must keep its label.
                    setKnown((previous) => mergeOptions(previous, incoming));
                    setLoading(false);
                })
                .catch((err) => {
                    if (id !== requestId.current) {
                        return;
                    }
                    // eslint-disable-next-line no-console
                    console.error('Proto-Blocks: failed to load control options', err);
                    setError(__('Could not load options.', 'proto-blocks'));
                    setLoading(false);
                });
        },
        [source, argsKey]
    );

    useEffect(() => {
        load('');
    }, [load]);

    // tsconfig has `strict: false`, so a typed single-parameter callback
    // satisfies the hook's generic without a cast -- block-factory.tsx passes
    // `(attrs: BlockAttributes) => ...` the same way.
    const debouncedSearch = useDebouncedCallback(load, 300);

    const index = useMemo(() => buildLabelIndex(known), [known]);
    const tokens = useMemo(() => keysToLabels(selected, index), [selected, index]);
    const suggestions = useMemo(
        () => suggestionsFor(index, selected),
        [index, selected]
    );

    const handleTokensChange = useCallback(
        (next: (string | { value: string })[]) => {
            const labels = next.map((token) =>
                typeof token === 'string' ? token : token.value
            );
            onChange(labelsToKeys(labels, index, selected));
        },
        [index, selected, onChange]
    );

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;
            if (!over || active.id === over.id) {
                return;
            }
            const oldIndex = selected.indexOf(String(active.id));
            const newIndex = selected.indexOf(String(over.id));
            if (oldIndex === -1 || newIndex === -1) {
                return;
            }
            onChange(arrayMove(selected, oldIndex, newIndex));
        },
        [selected, onChange]
    );

    const handleRemove = useCallback(
        (key: string) => onChange(selected.filter((k) => k !== key)),
        [selected, onChange]
    );

    return (
        <div className="proto-blocks-multiselect">
            <FormTokenField
                label={label}
                value={tokens}
                suggestions={suggestions}
                onChange={handleTokensChange}
                onInputChange={debouncedSearch}
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                __experimentalExpandOnFocus
            />

            {loading && <Spinner />}
            {error && <p className="components-base-control__help">{error}</p>}

            {selected.length > 1 && (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext
                        items={selected}
                        strategy={verticalListSortingStrategy}
                    >
                        <ul className="proto-blocks-multiselect__list">
                            {selected.map((key, position) => (
                                <SortableToken
                                    key={key}
                                    id={key}
                                    label={tokens[position]}
                                    onRemove={handleRemove}
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Wire it into the control renderer**

In `src/editor/controls/render.tsx`, add to the imports beside the existing `DynamicSelectControl` import:

```tsx
import { MultiSelectControl } from './MultiSelectControl';
```

and add a new `case` immediately after the closing of `case 'select':`:

```tsx
        case 'multiselect':
            return (
                <MultiSelectControl
                    label={config.label}
                    value={Array.isArray(value) ? (value as string[]) : []}
                    options={config.options}
                    source={config.optionsSource}
                    sourceArgs={config.sourceArgs}
                    onChange={onChange as (value: string[]) => void}
                />
            );
```

- [ ] **Step 3: Add the styles**

Append to `assets/css/editor.css`:

```css
/* Multiselect control -- the reorder list under the token field. */
.proto-blocks-multiselect__list {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.proto-blocks-multiselect__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	margin: 0 0 4px;
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 2px;
	font-size: 12px;
}

.proto-blocks-multiselect__item.is-dragging {
	opacity: 0.7;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
}

.proto-blocks-multiselect__handle {
	background: none;
	border: 0;
	cursor: grab;
	padding: 0 2px;
	color: #757575;
	line-height: 1;
}

.proto-blocks-multiselect__handle:active {
	cursor: grabbing;
}

.proto-blocks-multiselect__label {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
```

- [ ] **Step 4: Build, lint and verify manually**

Run: `npm run build`
Expected: builds with no TypeScript errors.

Run: `npm run lint:js`
Expected: no errors in the new files.

Then verify in a real editor. Copy the built plugin over the Cadco install and add a temporary control to an existing block:

```bash
rsync -a --delete \
  --exclude node_modules --exclude .git \
  ./ "/Users/gustavogomez/Local Sites/cadco/app/public/wp-content/plugins/proto-blocks/"
```

Add to `wp-content/themes/cadco-theme/proto-blocks/cadco-product-categories/block.json` under `protoBlocks.controls`, temporarily:

```json
"tmpPicks": {
  "type": "multiselect",
  "label": "TEMP multiselect check",
  "optionsSource": "wp:posts",
  "sourceArgs": { "post_type": "product", "per_page": 200 }
}
```

Then in wp-admin, edit the Home page and select the Cadco Product Categories block. Confirm each of:
  - the token field lists the six products as suggestions;
  - typing `bak` narrows to `Bakerlux Full Size Station` (a **server** query — watch the Network tab for `/proto-blocks/v1/controls/options?...search=bak`);
  - selecting three products shows three tokens and a three-row reorder list;
  - dragging row 3 to row 1 changes the order, and the order survives a save + reload;
  - removing a token removes its row.

Finally **remove** the temporary `tmpPicks` control from `block.json` and run `wp proto-blocks cache clear`.

- [ ] **Step 5: Commit**

```bash
git add src/editor/controls/MultiSelectControl.tsx src/editor/controls/render.tsx assets/css/editor.css
git commit -m "feat(controls): multiselect editor UI

FormTokenField for selection with debounced server-side search, plus a
dnd-kit list for ordering. Options accumulate across searches so a token
whose option has left the current result page keeps its label, and a
stale in-flight response cannot overwrite a newer one."
```

---

### Task 5: Plugin documentation

**Files:**
- Create: `docs/multiselect-control.md`
- Modify: `docs/dynamic-control-options.md` (cross-link)
- Modify: `README.md` (the `## Control Types` list, around line 620)

**Interfaces:**
- Consumes: the shipped behaviour from Tasks 1–4.
- Produces: nothing code depends on.

- [ ] **Step 1: Write the reference doc**

Create `docs/multiselect-control.md`:

````markdown
# The `multiselect` Control

`multiselect` stores an **ordered list** of option keys. It takes exactly the
same configuration as [`select`](./dynamic-control-options.md) — static
`options` or a server-loaded `optionsSource` — and differs only in arity.

```json
"featuredProducts": {
  "type": "multiselect",
  "label": "Featured products",
  "optionsSource": "wp:posts",
  "sourceArgs": { "post_type": "product", "per_page": 200 }
}
```

## The stored value

An array of option keys, as strings, in the order the author arranged them:

```php
$ids = $attributes['featuredProducts'] ?? [];   // ['18279', '18277', '18282']
```

Keys are strings even when they are numeric post IDs — the same rule `select`
follows.

## Reading it in a template

```php
$ids = $attributes['featuredProducts'] ?? [];

$products = $ids ? get_posts([
    'post_type'      => 'product',
    'post__in'       => array_map('intval', $ids),
    'orderby'        => 'post__in',
    'posts_per_page' => count($ids),
]) : [];

foreach ($products as $product) {
    // ...
}
```

> **`'orderby' => 'post__in'` is load-bearing.** Without it WordPress returns
> the posts in date order and throws away the ordering the author just dragged
> into place. This is the single most common mistake with this control.

A key whose post has since been deleted simply returns no row — the loop is
shorter, nothing errors. The editor keeps showing the key as a bare token so
the author can see and remove it.

## Authoring behaviour

- **Search hits the server.** `per_page` is clamped server-side to 1–200, so on
  a large catalogue the control queries as the author types (debounced) rather
  than filtering only the first page. Any provider that honours a `search`
  argument gets this for free; the built-in `wp:posts`, `wp:terms` and
  `wp:users` all do.
- **Order is drag-and-drop.** With two or more selections a reorder list
  appears under the token field.
- **Duplicate labels are disambiguated.** Two posts sharing a title show as
  `Half Size Convection Oven (#18282)`. The suffix is display-only; the stored
  value is always the bare key.

## Validation

A `multiselect` with neither `options` nor `optionsSource` is a hard schema
error, exactly as for `select`:

```
Control "featuredProducts" of type "multiselect" must have options or an optionsSource defined
```

## Custom providers

Anything registered on `proto_blocks_register_options_providers` works with
`multiselect` unchanged — see
[Dynamic / Server-Provided Options](./dynamic-control-options.md). A provider
that ignores the `search` argument still works; it just cannot narrow past its
own `per_page`.
````

- [ ] **Step 2: Cross-link from the existing doc**

Append to the end of `docs/dynamic-control-options.md`:

```markdown
## Selecting more than one

`optionsSource` also drives the [`multiselect`](./multiselect-control.md)
control, which stores an ordered array of keys instead of a single one. Same
providers, same `sourceArgs`, no extra server code.
```

- [ ] **Step 3: Add it to the README control list**

In `README.md`, in the `## Control Types` list, add immediately after the
`select` line:

```markdown
- `multiselect` - Ordered multi-selection storing an array of keys (same `options` / `optionsSource` contract as `select` — see [The multiselect Control](docs/multiselect-control.md))
```

- [ ] **Step 4: Verify the links resolve**

Run:

```bash
grep -n "multiselect-control.md" README.md docs/dynamic-control-options.md
ls docs/multiselect-control.md
```

Expected: both references print, and the file exists.

- [ ] **Step 5: Commit**

```bash
git add docs/multiselect-control.md docs/dynamic-control-options.md README.md
git commit -m "docs(controls): document the multiselect control"
```

---

### Task 6: Update the skill

The skill lives in a **separate repository**: `/Users/gustavogomez/Documents/Projects/Protoblocks/protoblocks-skill` (`git@github.com:GustavoGomez092/protoblocks-skill.git`, branch `main`).

**Files:**
- Modify: `skills/protoblocks/SKILL.md` (control-type line ~107; iron rule 6 ~line 142)
- Modify: `skills/protoblocks/references/controls.md` (control table; the dynamic-options section)

**Interfaces:**
- Consumes: the shipped behaviour from Tasks 1–5.
- Produces: nothing code depends on.

- [ ] **Step 1: Update the SKILL.md quick reference**

In `skills/protoblocks/SKILL.md`, under `### Control types (protoBlocks.controls)`, replace the type list sentence so it names `multiselect` and states the array value:

```markdown
`text`, `textarea`, `select`, `multiselect`, `toggle`, `checkbox`, `range`, `number`, `color`, `color-palette`, `radio`, `image`, `video`. Select/radio require `options` **or** (for `select` and `multiselect`) a server-loaded `optionsSource` (see `references/controls.md` → Dynamic / server-provided options); range expects `min`/`max`. `multiselect` stores an **ordered array of keys** and is drag-reorderable — read it with `orderby => 'post__in'` (see `references/controls.md` → Multiselect). Any control can declare `conditions.visible` to show only when other attributes match (see `references/controls.md` → Conditional rendering). `image`/`video` exist as **both** fields and controls — use the control form for a sidebar media picker.
```

- [ ] **Step 2: Update iron rule 6**

In the same file, replace iron rule 6:

```markdown
6. **`select` and `multiselect` controls must define `options`** (use `{ "key", "label" }` pairs) **or** an `optionsSource` (server-loaded options — see `references/controls.md`). Either type with neither fails validation.
```

- [ ] **Step 3: Add the row to the controls reference table**

In `skills/protoblocks/references/controls.md`, in the `## Control types` table, add a row immediately below the `select` row:

```markdown
| `multiselect` | array | list of option keys | **Requires `options` or `optionsSource`** — same contract as `select`. Stores an ordered `string[]`; drag to reorder. | featured-products |
```

- [ ] **Step 4: Document reading the value**

In the same file, at the end of the `## Dynamic / server-provided options` section (after "Registering a custom provider (PHP)" and before `## Conditional visibility`), add:

````markdown
### Multiselect

`multiselect` takes the identical config to `select` but stores an **ordered
array of keys**. The author picks with a token field (searching the server as
they type, so a catalogue past `per_page` is still reachable) and drags to
reorder.

```json
"featuredProducts": {
  "type": "multiselect", "label": "Featured products",
  "optionsSource": "wp:posts",
  "sourceArgs": { "post_type": "product", "per_page": 200 }
}
```

```php
$ids = $attributes['featuredProducts'] ?? [];

$products = $ids ? get_posts([
    'post_type'      => 'product',
    'post__in'       => array_map('intval', $ids),
    'orderby'        => 'post__in',   // <- keeps the author's drag order
    'posts_per_page' => count($ids),
]) : [];
```

**`orderby => 'post__in'` is load-bearing.** Omit it and WordPress returns date
order, discarding the ordering the author just dragged into place.

Two options sharing a label render disambiguated — `Half Size Oven (#18282)` —
but the stored value is always the bare key. A key whose post was deleted stays
visible as a bare token so it can be removed, and simply yields no row in the
query.
````

- [ ] **Step 5: Verify nothing contradicts the old text**

Run:

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/protoblocks-skill
grep -rn "multiselect" skills/
grep -rn "There are six built-in field types" skills/
```

Expected: `multiselect` appears in `SKILL.md` (twice) and `references/controls.md` (twice). The field-types sentence must still say **six** — `multiselect` is a *control*, not a field, and `references/fields.md` must not have been touched.

- [ ] **Step 6: Commit in the skill repo**

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/protoblocks-skill
git add skills/protoblocks/SKILL.md skills/protoblocks/references/controls.md
git commit -m "docs: document the multiselect control

Adds multiselect to the control-type quick reference and iron rule 6,
plus a reference section covering the ordered array value and the
orderby => post__in requirement for preserving drag order."
```

---

### Task 7: Ship

**Files:** none changed. This task merges and pushes.

**Interfaces:**
- Consumes: Tasks 1–6.
- Produces: a released plugin version the theme can depend on.

- [ ] **Step 1: Run the full test suite one more time**

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks
vendor/bin/phpunit
npm run test:js
npm run lint:js
npm run build
```

Expected: all green. Do not proceed past a failure.

- [ ] **Step 2: Confirm no attribution leaked into any commit**

```bash
git log main..feat/multiselect-control --format='%B' \
  | grep -iE "claude|anthropic|co-authored|generated with|🤖" \
  && echo "FOUND -- scrub before pushing" || echo "clean"
```

Expected: `clean`. If anything is found, rewrite the messages with
`git rebase -i` or `git filter-branch --msg-filter` **before** pushing.

- [ ] **Step 3: Ask the user before pushing**

Pushing to `main` triggers `build-and-release.yml` and `release.yml`, which cut
a real release. Confirm with the user first, and confirm whether they want the
branch merged directly or opened as a PR.

- [ ] **Step 4: Merge and push the plugin**

On approval:

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks
git checkout main
git merge --no-ff feat/multiselect-control -m "feat(controls): add the multiselect control"
git push origin main
```

- [ ] **Step 5: Push the skill**

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/protoblocks-skill
git push origin main
```

- [ ] **Step 6: Verify the release**

```bash
cd /Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks
gh run list --limit 3
gh release list --limit 3
```

Expected: the workflows succeed and a new minor version appears (2.8.x → 2.9.0,
since the branch carries `feat:` commits).

- [ ] **Step 7: Update the Cadco install**

Once the release exists, update the plugin on the Cadco site through the normal
WordPress update flow (the GitHub self-updater), then confirm:

```bash
cd "/Users/gustavogomez/Local Sites/cadco/app/public"
wp plugin get proto-blocks --field=version
```

Expected: the new version. The `cadco-featured-products` carousel is built
next, against this control.

---

## Self-Review

**Spec coverage**

| Spec requirement | Task |
|---|---|
| `multiselect` renders a token field; attribute is an array of keys | 2, 4 |
| Same `options` / `optionsSource` / `sourceArgs` contract as `select` | 2, 4 |
| Server-side search past the `per_page` clamp | 4 |
| Drag reordering, stored order = render order | 4 |
| Validator accepts it; hard error with neither options nor source | 2 |
| Values sanitised server-side | 1 |
| `Registry::sanitize()` missing `array` arm | 1 |
| `VALID_CONTROL_TYPES` stale (5 types warning spuriously) | 2 |
| Label→key collisions disambiguated | 3 |
| Selected token keeps its label after a search narrows results | 3 (`mergeOptions`), 4 |
| Deleted/unresolvable key stays visible and removable | 3 (`keysToLabels`, `labelsToKeys` fallback) |
| Error state: "Could not load options." | 4 |
| Plugin docs + README | 5 |
| Skill docs | 6 |
| Two conventional commits, semantic-release | 1–7 |
| No new config keys (`min`/`max`/`sortable`) | Global Constraints |

No gaps.

**Type consistency**

`SelectOption` (`{ value, label }`) is the shape throughout Tasks 3 and 4;
`LabelIndex` exposes `labelByKey` / `keyByLabel` and is used under exactly those
names in `MultiSelectControl`. `labelsToKeys` is called with its third
`fallbackKeys` argument in Task 4, matching the optional parameter defined in
Task 3. The control's `onChange` is `(value: string[]) => void` in both the
component and the `render.tsx` case.
