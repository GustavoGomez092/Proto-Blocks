# Server-Injected Control Options Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Proto-Blocks `select` control populate its options from the server — WP relationships (posts/terms/users), custom PHP methods, and static option tables — fetched live in the editor via REST.

**Architecture:** A new PHP **options-provider registry** maps a string `optionsSource` (e.g. `wp:posts`) to a callback returning `[{key,label}]`. A new REST route `GET proto-blocks/v1/controls/options` resolves a source+args to options. In the editor, a `select` control that declares `optionsSource` renders a new async `DynamicSelectControl` that fetches options on mount via `apiFetch`. Static `select` controls and all other controls are unchanged.

**Tech Stack:** PHP 8.0+ (WordPress plugin, custom autoloader), PHPUnit 10 (introduced for unit tests), TypeScript/React via `@wordpress/scripts` (Jest for unit tests), `@wordpress/api-fetch`, `@wordpress/url`.

---

## Author-facing API (target end state)

```json
"controls": {
  "relatedPage": {
    "type": "select",
    "label": "Related Page",
    "optionsSource": "wp:posts",
    "sourceArgs": { "post_type": "page", "per_page": 50 }
  },
  "category": {
    "type": "select",
    "label": "Category",
    "optionsSource": "wp:terms",
    "sourceArgs": { "taxonomy": "category" }
  },
  "currency": {
    "type": "select",
    "label": "Currency",
    "optionsSource": "currencies"
  }
}
```

A control is "dynamic" iff it carries `optionsSource`. Custom sources (`currencies`) are registered by developers on the `proto_blocks_register_options_providers` action.

## File Structure

| File | Responsibility | Action |
|------|----------------|--------|
| `composer.json` | Dev-only PHPUnit + PSR-4 autoload for tests | Create |
| `phpunit.xml.dist` | PHPUnit config | Create |
| `tests/php/bootstrap.php` | Autoloader + minimal WP function polyfills for unit tests | Create |
| `tests/php/Controls/OptionsProvidersTest.php` | Unit tests for the provider registry | Create |
| `tests/php/Schema/SchemaValidatorDynamicSelectTest.php` | Unit test: select+optionsSource is valid | Create |
| `includes/Controls/OptionsProviders.php` | Provider registry: register / has / get / resolve / normalizeOptions | Create |
| `includes/Core/Plugin.php` | Lazy `getOptionsProviders()`, register built-ins in `boot()`, fire action, pass providers to `RestAPI` | Modify |
| `includes/Schema/SchemaValidator.php:183` | Allow `select` with `optionsSource` but no static `options` | Modify |
| `includes/API/RestAPI.php` | Accept providers in ctor; add `/controls/options` route + callback | Modify |
| `src/editor/types.ts:16` | Add `optionsSource?` / `sourceArgs?` to `ControlConfig` | Modify |
| `src/editor/controls/options-source.ts` | Pure helper `fetchControlOptions(source, args)` | Create |
| `src/editor/controls/__tests__/options-source.test.ts` | Jest test for the helper | Create |
| `src/editor/controls/DynamicSelectControl.tsx` | Async select component | Create |
| `src/editor/controls/render.tsx:61` | Dispatch to `DynamicSelectControl` when `optionsSource` set | Modify |
| `package.json` | Add `test:js` script | Modify |
| `examples/` + docs | Example block + reference docs | Create/Modify |

---

## Task 0: Test tooling bootstrap

No PHP or JS unit-test harness exists today. This task introduces minimal, dev-only tooling so the rest of the plan can be TDD.

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/php/bootstrap.php`
- Modify: `package.json` (scripts)

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "proto-blocks/proto-blocks",
  "description": "Dev tooling for Proto-Blocks unit tests.",
  "license": "GPL-2.0-or-later",
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": { "ProtoBlocks\\": "includes/" }
  },
  "autoload-dev": {
    "psr-4": { "ProtoBlocks\\Tests\\": "tests/php/" }
  },
  "scripts": {
    "test": "phpunit"
  },
  "config": {
    "optimize-autoloader": true
  }
}
```

- [ ] **Step 2: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/php/bootstrap.php"
         colors="true"
         failOnWarning="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/php</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/php/bootstrap.php`**

Defines no-op polyfills for the handful of WP functions our unit-tested classes touch, so tests run without loading WordPress.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return is_string($text) ? trim($text) : $text; }
}
```

- [ ] **Step 4: Install PHP dev deps and verify PHPUnit runs**

Run:
```bash
cd "/Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks"
composer install
vendor/bin/phpunit --version
```
Expected: PHPUnit 10.5.x version string prints; no fatal errors.

- [ ] **Step 5: Add JS test script to `package.json`**

In the `"scripts"` block, add the `test:js` line (keep existing scripts):

```json
"format": "wp-scripts format",
"test:js": "wp-scripts test-unit-js",
"packages-update": "wp-scripts packages-update",
```

- [ ] **Step 6: Verify the JS test runner resolves**

Run:
```bash
cd "/Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks"
npm run test:js -- --passWithNoTests
```
Expected: Jest starts and exits 0 with "No tests found, exiting with code 0" (or equivalent).

- [ ] **Step 7: Ignore test artifacts and commit**

Add to `.gitignore` if not already present: `/vendor/`. Then:
```bash
git add composer.json phpunit.xml.dist tests/php/bootstrap.php package.json .gitignore
git commit -m "chore: add phpunit and jest unit-test tooling"
```

---

## Task 1: OptionsProviders registry (pure PHP, TDD)

The core registry. Pure PHP — no WordPress calls — so it is fully unit-testable.

**Files:**
- Create: `includes/Controls/OptionsProviders.php`
- Test: `tests/php/Controls/OptionsProvidersTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Controls;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Controls\OptionsProviders;

final class OptionsProvidersTest extends TestCase
{
    public function test_register_and_has(): void
    {
        $providers = new OptionsProviders();
        $providers->register('demo', fn(array $args) => []);

        $this->assertTrue($providers->has('demo'));
        $this->assertFalse($providers->has('missing'));
    }

    public function test_resolve_returns_normalized_options_from_callback(): void
    {
        $providers = new OptionsProviders();
        $providers->register('demo', fn(array $args) => [
            ['key' => '1', 'label' => 'One'],
            ['key' => '2', 'label' => 'Two'],
        ]);

        $this->assertSame(
            [
                ['key' => '1', 'label' => 'One'],
                ['key' => '2', 'label' => 'Two'],
            ],
            $providers->resolve('demo')
        );
    }

    public function test_resolve_normalizes_map_and_list_shapes(): void
    {
        $providers = new OptionsProviders();
        $providers->register('map', fn(array $args) => ['usd' => 'US Dollar', 'eur' => 'Euro']);

        $this->assertSame(
            [
                ['key' => 'usd', 'label' => 'US Dollar'],
                ['key' => 'eur', 'label' => 'Euro'],
            ],
            $providers->resolve('map')
        );
    }

    public function test_resolve_filters_args_to_allowed_keys(): void
    {
        $captured = [];
        $providers = new OptionsProviders();
        $providers->register('demo', function (array $args) use (&$captured) {
            $captured = $args;
            return [];
        }, ['post_type']);

        $providers->resolve('demo', ['post_type' => 'page', 'evil' => 'rm -rf']);

        $this->assertSame(['post_type' => 'page'], $captured);
    }

    public function test_resolve_passes_all_args_when_no_whitelist(): void
    {
        $captured = [];
        $providers = new OptionsProviders();
        $providers->register('demo', function (array $args) use (&$captured) {
            $captured = $args;
            return [];
        });

        $providers->resolve('demo', ['anything' => 'goes']);

        $this->assertSame(['anything' => 'goes'], $captured);
    }

    public function test_resolve_throws_on_unknown_source(): void
    {
        $providers = new OptionsProviders();

        $this->expectException(\InvalidArgumentException::class);
        $providers->resolve('nope');
    }

    public function test_resolve_returns_empty_array_when_callback_returns_non_array(): void
    {
        $providers = new OptionsProviders();
        $providers->register('bad', fn(array $args) => null);

        $this->assertSame([], $providers->resolve('bad'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsProvidersTest`
Expected: FAIL — "Class ProtoBlocks\Controls\OptionsProviders not found".

- [ ] **Step 3: Implement `includes/Controls/OptionsProviders.php`**

```php
<?php
/**
 * Options Providers - Resolves server-provided options for dynamic controls.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Controls;

/**
 * Registry mapping an options "source" identifier to a callback that returns
 * an array of { key, label } options for a dynamic select control.
 */
class OptionsProviders
{
    /**
     * @var array<string, array{callback: callable, allowed_args: array<int, string>}>
     */
    private array $providers = [];

    /**
     * Register an options provider.
     *
     * @param string             $name        Source identifier, e.g. "wp:posts".
     * @param callable            $callback    fn(array $args): array — returns {key,label}[] or a key=>label map.
     * @param array<int, string>  $allowedArgs Whitelist of arg keys forwarded to the callback. Empty = allow all.
     */
    public function register(string $name, callable $callback, array $allowedArgs = []): void
    {
        $this->providers[$name] = [
            'callback' => $callback,
            'allowed_args' => $allowedArgs,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @return array{callback: callable, allowed_args: array<int, string>}|null
     */
    public function get(string $name): ?array
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return array<string, array{callback: callable, allowed_args: array<int, string>}>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Resolve a source to normalized options.
     *
     * @param array<string, mixed> $args
     * @return array<int, array{key: string, label: string}>
     * @throws \InvalidArgumentException When the source is not registered.
     */
    public function resolve(string $name, array $args = []): array
    {
        $provider = $this->get($name);

        if ($provider === null) {
            throw new \InvalidArgumentException(
                sprintf('Unknown options source "%s"', $name)
            );
        }

        $allowed = $provider['allowed_args'];
        $filtered = empty($allowed)
            ? $args
            : array_intersect_key($args, array_flip($allowed));

        $result = ($provider['callback'])($filtered);

        if (!is_array($result)) {
            return [];
        }

        return self::normalizeOptions($result);
    }

    /**
     * Normalize a {key,label}[] list or a key=>label map to {key,label}[].
     *
     * @param array<mixed> $options
     * @return array<int, array{key: string, label: string}>
     */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $normalized[] = [
                    'key' => (string) ($value['key'] ?? $value['value'] ?? $key),
                    'label' => (string) ($value['label'] ?? $value['value'] ?? $key),
                ];
            } else {
                $normalized[] = [
                    'key' => (string) (is_int($key) ? $value : $key),
                    'label' => (string) $value,
                ];
            }
        }

        return $normalized;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter OptionsProvidersTest`
Expected: PASS — 7 tests, all green.

- [ ] **Step 5: Commit**

```bash
git add includes/Controls/OptionsProviders.php tests/php/Controls/OptionsProvidersTest.php
git commit -m "feat: add OptionsProviders registry for dynamic control options"
```

---

## Task 2: Built-in WP providers + Plugin wiring

Register `wp:posts`, `wp:terms`, `wp:users` and expose the registry through the Plugin, firing an extension action. The WP providers call `WP_Query`/`get_terms`/`get_users`, so they are verified manually (Task 7), not unit-tested.

**Files:**
- Modify: `includes/Core/Plugin.php` (add getter, boot registration, action; ~lines 80-102, 145-198, 340-360)

- [ ] **Step 1: Add the lazy getter**

In `includes/Core/Plugin.php`, next to `getControlRegistry()` (around line 354), add an import at the top of the file (with the other `use ProtoBlocks\...` statements):

```php
use ProtoBlocks\Controls\OptionsProviders;
```

Then add the getter method alongside the other service getters:

```php
public function getOptionsProviders(): OptionsProviders
{
    if (!isset($this->services['options_providers'])) {
        $this->services['options_providers'] = new OptionsProviders();
    }
    return $this->services['options_providers'];
}
```

- [ ] **Step 2: Register built-in providers and fire the extension action in `boot()`**

In `boot()` (line 80-102), after `$this->registerCoreControlTypes();` (line 92) and before `do_action('proto_blocks_init', $this);` (line 95), add:

```php
        // Register built-in options providers for dynamic controls
        $this->registerCoreOptionsProviders();

        // Allow extensions to register custom options providers
        do_action('proto_blocks_register_options_providers', $this->getOptionsProviders());
```

- [ ] **Step 3: Implement `registerCoreOptionsProviders()`**

Add this method to `Plugin` (place it after `registerCoreControlTypes()`, around line 198):

```php
    /**
     * Register built-in options providers (WP relationships).
     */
    private function registerCoreOptionsProviders(): void
    {
        $providers = $this->getOptionsProviders();

        $providers->register('wp:posts', function (array $args): array {
            $query = new \WP_Query([
                'post_type'      => $args['post_type'] ?? 'post',
                'post_status'    => 'publish',
                'posts_per_page' => (int) ($args['per_page'] ?? 50),
                's'              => (string) ($args['search'] ?? ''),
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            return array_map(
                static fn(\WP_Post $post): array => [
                    'key'   => (string) $post->ID,
                    'label' => $post->post_title !== '' ? $post->post_title : __('(no title)', 'proto-blocks'),
                ],
                $query->posts
            );
        }, ['post_type', 'per_page', 'search']);

        $providers->register('wp:terms', function (array $args): array {
            $terms = get_terms([
                'taxonomy'   => $args['taxonomy'] ?? 'category',
                'hide_empty' => false,
                'number'     => (int) ($args['per_page'] ?? 100),
                'search'     => (string) ($args['search'] ?? ''),
            ]);

            if (is_wp_error($terms)) {
                return [];
            }

            return array_map(
                static fn($term): array => [
                    'key'   => (string) $term->term_id,
                    'label' => $term->name,
                ],
                $terms
            );
        }, ['taxonomy', 'per_page', 'search']);

        $providers->register('wp:users', function (array $args): array {
            $users = get_users([
                'number'  => (int) ($args['per_page'] ?? 50),
                'search'  => $args['search'] ? '*' . $args['search'] . '*' : '',
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ]);

            return array_map(
                static fn(\WP_User $user): array => [
                    'key'   => (string) $user->ID,
                    'label' => $user->display_name,
                ],
                $users
            );
        }, ['per_page', 'search']);
    }
```

- [ ] **Step 4: Verify no PHP syntax errors**

Run: `php -l includes/Core/Plugin.php`
Expected: "No syntax errors detected in includes/Core/Plugin.php".

- [ ] **Step 5: Commit**

```bash
git add includes/Core/Plugin.php
git commit -m "feat: register built-in wp:posts/terms/users options providers"
```

---

## Task 3: Allow dynamic select in the schema validator (TDD)

A `select` with `optionsSource` but no static `options` must NOT be a hard error.

**Files:**
- Modify: `includes/Schema/SchemaValidator.php:183`
- Test: `tests/php/Schema/SchemaValidatorDynamicSelectTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Schema;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Schema\SchemaValidator;

final class SchemaValidatorDynamicSelectTest extends TestCase
{
    private function baseSchema(array $controls): array
    {
        return [
            'name' => 'proto-blocks/demo',
            'protoBlocks' => [
                'version' => '1.0',
                'template' => 'template.php',
                'controls' => $controls,
            ],
        ];
    }

    public function test_select_with_options_source_is_valid(): void
    {
        $validator = new SchemaValidator();

        $valid = $validator->validate($this->baseSchema([
            'relatedPage' => [
                'type' => 'select',
                'optionsSource' => 'wp:posts',
            ],
        ]));

        $this->assertTrue($valid);
    }

    public function test_select_without_options_or_source_throws(): void
    {
        $validator = new SchemaValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($this->baseSchema([
            'broken' => ['type' => 'select'],
        ]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SchemaValidatorDynamicSelectTest`
Expected: `test_select_with_options_source_is_valid` FAILS by throwing `InvalidArgumentException` ("Select control ... must have options defined").

- [ ] **Step 3: Relax the validator rule**

In `includes/Schema/SchemaValidator.php`, change the block at lines 182-188 from:

```php
        // Select controls must have options
        if ($type === 'select' && empty($control['options'])) {
            $this->errors[] = sprintf(
                'Select control "%s" must have options defined',
                $name
            );
        }
```

to:

```php
        // Select controls must have static options OR a dynamic options source
        if ($type === 'select' && empty($control['options']) && empty($control['optionsSource'])) {
            $this->errors[] = sprintf(
                'Select control "%s" must have options or an optionsSource defined',
                $name
            );
        }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SchemaValidatorDynamicSelectTest`
Expected: PASS — 2 tests green.

- [ ] **Step 5: Run the full PHP suite (no regressions)**

Run: `vendor/bin/phpunit`
Expected: PASS — all tests green.

- [ ] **Step 6: Commit**

```bash
git add includes/Schema/SchemaValidator.php tests/php/Schema/SchemaValidatorDynamicSelectTest.php
git commit -m "feat: accept select controls with optionsSource in validator"
```

---

## Task 4: REST endpoint for dynamic options

Add `GET proto-blocks/v1/controls/options?source=...&args=<json>` returning `{ options, total }`. Pass the providers registry into `RestAPI`. The route plumbing is verified manually (Task 7); the resolution logic it delegates to is already unit-tested in Task 1.

**Files:**
- Modify: `includes/API/RestAPI.php` (ctor + routes + callback)
- Modify: `includes/Core/Plugin.php:228` (pass providers into `RestAPI`)

- [ ] **Step 1: Add the provider dependency to `RestAPI`**

In `includes/API/RestAPI.php`, add the import near the other `use` statements (after line 18):

```php
use ProtoBlocks\Controls\OptionsProviders;
```

Add the property after `private Cache $cache;` (line 43):

```php
    /**
     * Options providers
     */
    private OptionsProviders $optionsProviders;
```

Change the constructor signature and body (lines 48-53) to:

```php
    public function __construct(
        Engine $engine,
        Registrar $registrar,
        Cache $cache,
        OptionsProviders $optionsProviders
    ) {
        $this->engine = $engine;
        $this->registrar = $registrar;
        $this->cache = $cache;
        $this->optionsProviders = $optionsProviders;
    }
```

- [ ] **Step 2: Register the new route**

In `registerRoutes()`, after the cache route block (after line 111, before the closing `}`), add:

```php
        // Dynamic control options
        register_rest_route(self::NAMESPACE, '/controls/options', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getControlOptions'],
            'permission_callback' => [$this, 'canEditPosts'],
            'args' => [
                'source' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'args' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);
```

- [ ] **Step 3: Implement the callback**

Add this method after `getBlockSettings()` (after line 194):

```php
    /**
     * Get options for a dynamic control source.
     */
    public function getControlOptions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $source = (string) $request->get_param('source');
        $rawArgs = $request->get_param('args');

        $args = [];
        if (is_string($rawArgs) && $rawArgs !== '') {
            $decoded = json_decode($rawArgs, true);
            if (is_array($decoded)) {
                $args = $decoded;
            }
        } elseif (is_array($rawArgs)) {
            $args = $rawArgs;
        }

        if (!$this->optionsProviders->has($source)) {
            return new WP_Error(
                'proto_blocks_unknown_source',
                __('Unknown options source.', 'proto-blocks'),
                ['status' => 400]
            );
        }

        try {
            $options = $this->optionsProviders->resolve($source, $args);
        } catch (\Throwable $e) {
            return new WP_Error(
                'proto_blocks_options_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'options' => $options,
            'total' => count($options),
        ], 200);
    }
```

- [ ] **Step 4: Pass the providers registry when constructing `RestAPI`**

In `includes/Core/Plugin.php`, update the `RestAPI` instantiation (lines 228-232) to:

```php
        // REST API
        $this->services['rest_api'] = new RestAPI(
            $this->getEngine(),
            $this->getRegistrar(),
            $this->getCache(),
            $this->getOptionsProviders()
        );
```

- [ ] **Step 5: Verify no PHP syntax errors**

Run:
```bash
php -l includes/API/RestAPI.php && php -l includes/Core/Plugin.php
```
Expected: "No syntax errors detected" for both files.

- [ ] **Step 6: Commit**

```bash
git add includes/API/RestAPI.php includes/Core/Plugin.php
git commit -m "feat: add REST endpoint for dynamic control options"
```

---

## Task 5: Editor options-fetch helper (TDD, Jest)

A pure helper that calls the REST endpoint and maps `{key,label}` → `{value,label}` (the shape `SelectControl` expects).

**Files:**
- Create: `src/editor/controls/options-source.ts`
- Test: `src/editor/controls/__tests__/options-source.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import apiFetch from '@wordpress/api-fetch';
import { fetchControlOptions } from '../options-source';

jest.mock('@wordpress/api-fetch');

const mockedApiFetch = apiFetch as unknown as jest.Mock;

describe('fetchControlOptions', () => {
    beforeEach(() => {
        mockedApiFetch.mockReset();
    });

    it('requests the options endpoint with source and JSON-encoded args', async () => {
        mockedApiFetch.mockResolvedValue({ options: [], total: 0 });

        await fetchControlOptions('wp:posts', { post_type: 'page' });

        expect(mockedApiFetch).toHaveBeenCalledTimes(1);
        const path = mockedApiFetch.mock.calls[0][0].path as string;
        expect(path).toContain('/proto-blocks/v1/controls/options');
        expect(path).toContain('source=wp%3Aposts');
        expect(path).toContain(encodeURIComponent(JSON.stringify({ post_type: 'page' })));
    });

    it('maps {key,label} responses to {value,label}', async () => {
        mockedApiFetch.mockResolvedValue({
            options: [
                { key: '1', label: 'One' },
                { key: '2', label: 'Two' },
            ],
            total: 2,
        });

        const result = await fetchControlOptions('wp:posts');

        expect(result).toEqual([
            { value: '1', label: 'One' },
            { value: '2', label: 'Two' },
        ]);
    });

    it('returns an empty array when the response has no options', async () => {
        mockedApiFetch.mockResolvedValue({});

        const result = await fetchControlOptions('currencies');

        expect(result).toEqual([]);
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- options-source`
Expected: FAIL — cannot find module `../options-source`.

- [ ] **Step 3: Implement `src/editor/controls/options-source.ts`**

```ts
/**
 * Fetches dynamic control options from the Proto-Blocks REST API.
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export interface SelectOption {
    value: string;
    label: string;
}

interface OptionsResponse {
    options?: Array<{ key: string; label: string }>;
    total?: number;
}

const ENDPOINT = '/proto-blocks/v1/controls/options';

/**
 * Fetch options for a dynamic select control.
 *
 * @param source Source identifier, e.g. "wp:posts".
 * @param args   Source-specific arguments, forwarded to the server provider.
 */
export async function fetchControlOptions(
    source: string,
    args: Record<string, unknown> = {}
): Promise<SelectOption[]> {
    const path = addQueryArgs(ENDPOINT, {
        source,
        args: JSON.stringify(args),
    });

    const response = await apiFetch<OptionsResponse>({ path });

    return (response.options || []).map((opt) => ({
        value: opt.key,
        label: opt.label,
    }));
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- options-source`
Expected: PASS — 3 tests green.

- [ ] **Step 5: Commit**

```bash
git add src/editor/controls/options-source.ts src/editor/controls/__tests__/options-source.test.ts
git commit -m "feat: add fetchControlOptions editor helper"
```

---

## Task 6: DynamicSelectControl component + render dispatch + types

Wire the helper into a React component and dispatch to it from `renderControl`.

**Files:**
- Modify: `src/editor/types.ts:16-30`
- Create: `src/editor/controls/DynamicSelectControl.tsx`
- Modify: `src/editor/controls/render.tsx:42,61`

- [ ] **Step 1: Extend `ControlConfig` in `src/editor/types.ts`**

In the `ControlConfig` interface (lines 16-30), add two fields after `options?:`:

```ts
interface ControlConfig {
    type: string;
    label: string;
    default?: unknown;
    options?: Array<{ key: string; label: string }>;
    optionsSource?: string;
    sourceArgs?: Record<string, unknown>;
    min?: number;
    max?: number;
    step?: number;
    conditions?: { visible?: Record<string, unknown> };
    affects?: string[];
}
```

(Keep any existing fields not shown here unchanged — only add `optionsSource` and `sourceArgs`.)

- [ ] **Step 2: Create `src/editor/controls/DynamicSelectControl.tsx`**

```tsx
/**
 * A select control whose options are loaded from the server on mount.
 */

import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchControlOptions, SelectOption } from './options-source';

interface DynamicSelectControlProps {
    label: string;
    value: string;
    source: string;
    sourceArgs?: Record<string, unknown>;
    onChange: (value: string) => void;
}

export function DynamicSelectControl({
    label,
    value,
    source,
    sourceArgs = {},
    onChange,
}: DynamicSelectControlProps): JSX.Element {
    const [options, setOptions] = useState<SelectOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Serialize args so the effect re-runs only when they actually change.
    const argsKey = JSON.stringify(sourceArgs);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);

        fetchControlOptions(source, sourceArgs)
            .then((opts) => {
                if (active) {
                    setOptions(opts);
                    setLoading(false);
                }
            })
            .catch(() => {
                if (active) {
                    setError(__('Could not load options.', 'proto-blocks'));
                    setLoading(false);
                }
            });

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [source, argsKey]);

    if (loading) {
        return (
            <div className="proto-blocks-dynamic-select is-loading">
                <label className="components-base-control__label">{label}</label>
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div className="proto-blocks-dynamic-select has-error">
                <label className="components-base-control__label">{label}</label>
                <p className="components-base-control__help">{error}</p>
            </div>
        );
    }

    return (
        <SelectControl
            label={label}
            value={value}
            options={[
                { value: '', label: __('— Select —', 'proto-blocks') },
                ...options,
            ]}
            onChange={onChange}
            __next40pxDefaultSize
            __nextHasNoMarginBottom
        />
    );
}
```

- [ ] **Step 3: Dispatch to it from `renderControl` in `src/editor/controls/render.tsx`**

Add the import after line 22 (`import { __ } from '@wordpress/i18n';`):

```tsx
import { DynamicSelectControl } from './DynamicSelectControl';
```

Then change the `case 'select':` branch (lines 61-76) so a dynamic source short-circuits to the new component:

```tsx
        case 'select':
            if (config.optionsSource) {
                return (
                    <DynamicSelectControl
                        label={config.label}
                        value={(value as string) || ''}
                        source={config.optionsSource}
                        sourceArgs={config.sourceArgs}
                        onChange={onChange}
                    />
                );
            }
            return (
                <SelectControl
                    label={config.label}
                    value={(value as string) || ''}
                    options={
                        config.options?.map((opt) => ({
                            value: opt.key,
                            label: opt.label,
                        })) || []
                    }
                    onChange={onChange}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                />
            );
```

- [ ] **Step 4: Type-check and build the editor bundle**

Run: `npm run build`
Expected: Build completes with no TypeScript errors; `assets/js/editor.js` is regenerated.

- [ ] **Step 5: Run the JS test suite (no regressions)**

Run: `npm run test:js`
Expected: PASS — all tests green.

- [ ] **Step 6: Commit**

```bash
git add src/editor/types.ts src/editor/controls/DynamicSelectControl.tsx src/editor/controls/render.tsx assets/js/editor.js
git commit -m "feat: render dynamic select controls from optionsSource"
```

---

## Task 7: Example block, end-to-end manual verification, and docs

Prove the feature works in a real editor and document it.

**Files:**
- Create: `examples/dynamic-select/block.json`
- Create: `examples/dynamic-select/template.php`
- Modify: `docs/` reference (or the skill's `references/controls.md` if maintained in-repo)

- [ ] **Step 1: Create the example block `examples/dynamic-select/block.json`**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "proto-blocks/dynamic-select",
  "title": "Dynamic Select Demo",
  "category": "proto-blocks",
  "icon": "admin-links",
  "supports": { "html": false },
  "protoBlocks": {
    "version": "1.0",
    "template": "template.php",
    "useTailwind": false,
    "fields": {
      "heading": { "type": "text", "tagName": "h3" }
    },
    "controls": {
      "relatedPage": {
        "type": "select",
        "label": "Related Page",
        "optionsSource": "wp:posts",
        "sourceArgs": { "post_type": "page", "per_page": 50 }
      },
      "category": {
        "type": "select",
        "label": "Category",
        "optionsSource": "wp:terms",
        "sourceArgs": { "taxonomy": "category" }
      }
    }
  }
}
```

- [ ] **Step 2: Create `examples/dynamic-select/template.php`**

```php
<?php
$heading     = $attributes['heading'] ?? '';
$relatedPage = $attributes['relatedPage'] ?? '';
$category    = $attributes['category'] ?? '';

$pageTitle = $relatedPage ? get_the_title((int) $relatedPage) : '';
$termName  = $category ? (get_term((int) $category)->name ?? '') : '';
?>
<div <?php echo get_block_wrapper_attributes(['class' => 'dynamic-select-demo']); ?>>
    <h3 data-proto-field="heading"><?php echo esc_html($heading); ?></h3>
    <?php if ($pageTitle) : ?>
        <p><?php echo esc_html__('Related page:', 'proto-blocks'); ?> <?php echo esc_html($pageTitle); ?></p>
    <?php endif; ?>
    <?php if ($termName) : ?>
        <p><?php echo esc_html__('Category:', 'proto-blocks'); ?> <?php echo esc_html($termName); ?></p>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Manually verify the REST endpoint**

With the plugin active in a WordPress install, logged in as an editor, run from the browser console or via authenticated curl:

```bash
# Replace COOKIE/NONCE as appropriate, or test from the editor's browser console:
wp.apiFetch({ path: '/proto-blocks/v1/controls/options?source=wp:posts&args=' + encodeURIComponent(JSON.stringify({post_type:'page'})) }).then(console.log)
```
Expected: `{ options: [ { key: "<id>", label: "<page title>" }, ... ], total: N }`.

Also verify the guard: `source=does-not-exist` returns HTTP 400 with code `proto_blocks_unknown_source`.

- [ ] **Step 4: Manually verify in the block editor**

1. Copy `examples/dynamic-select/` into the active theme's `proto-blocks/` directory (or ensure examples are a discovered path).
2. Run `wp proto-blocks cache clear`.
3. Insert the "Dynamic Select Demo" block in a post.
4. Confirm the inspector shows a "Related Page" select that briefly shows a spinner, then lists existing pages, and a "Category" select listing categories.
5. Select a page and a category; confirm the preview updates and the saved frontend renders the chosen titles.

Record the result (pass/fail with notes) in the commit message or PR description — do not claim success without observing steps 3-5.

- [ ] **Step 5: Document the feature**

Add a "Dynamic / server-provided options" subsection to the controls reference documentation, covering:
- The `optionsSource` + `sourceArgs` block.json syntax (copy the API example from the top of this plan).
- The three built-in sources: `wp:posts` (`post_type`, `per_page`, `search`), `wp:terms` (`taxonomy`, `per_page`, `search`), `wp:users` (`per_page`, `search`).
- How to register a custom provider:

```php
add_action('proto_blocks_register_options_providers', function ($providers) {
    $providers->register('currencies', function (array $args): array {
        return [
            ['key' => 'usd', 'label' => 'US Dollar'],
            ['key' => 'eur', 'label' => 'Euro'],
        ];
    });
});
```

- [ ] **Step 6: Commit**

```bash
git add examples/dynamic-select/ docs/
git commit -m "docs: add dynamic-select example block and options-source docs"
```

---

## Final Verification

- [ ] **Run the full PHP suite:** `vendor/bin/phpunit` → all green.
- [ ] **Run the full JS suite:** `npm run test:js` → all green.
- [ ] **Production build:** `npm run build` → no errors.
- [ ] **Lint:** `npm run lint:js` → no new errors in changed files.
- [ ] **Manual editor check (Task 7, steps 3-5) observed passing.**

---

## Notes & Deferred Items (out of scope for this plan)

These were raised during design and are intentionally **not** included; open follow-ups if needed:

- **Search-as-you-type for large datasets.** Current built-ins accept a `search` arg but the editor sends a fixed request on mount. A future `ComboboxControl`-based variant could debounce a `search` query param.
- **Server-side caching/transients** for expensive custom providers.
- **Multi-select relationships** (storing an array of ids). Current `select` is single-value; a `multiSelect` flag + array data type would be a separate enhancement.
- **`radio` dynamic options.** Only `select` dispatches to the dynamic path; `radio` still uses static `options`. Extend `render.tsx`'s `radio` branch similarly if needed.
