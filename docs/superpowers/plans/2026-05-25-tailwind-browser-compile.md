# Environment-Aware Tailwind Compile (Browser Engine) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Proto-Blocks compile Tailwind CSS entirely in the browser when the server has no shell access (e.g. WP Engine), while keeping the existing server-side CLI compile where a shell is available.

**Architecture:** Preserve the 5-step compile pipeline (`Scanner` → `ConfigEditor` input → **engine** → `Scoper` → `Cache`). Only the engine step varies. `Manager::isShellAvailable()` selects the engine. The browser engine fetches `{ inputCss, content }` from the server (pure PHP), compiles client-side with a bundled Tailwind v4 engine (tailwindcss JS + `oxide`/`lightningcss` WASM), and POSTs the CSS back; the server scopes it (`Scoper`) and writes it (`Cache`) — no `exec` anywhere. Serving is the existing `Assets::enqueueTailwindCss()`, unchanged.

**Tech Stack:** PHP 8.1+ (PHPUnit 10), TypeScript via `@wordpress/scripts` (Jest), WordPress admin-ajax, Tailwind v4 browser build + WebAssembly.

**Reference implementation:** WindPress 3.2.83 (`/tmp/windpress-inspect/windpress` if still unpacked; otherwise `~/Downloads/windpress.3.2.83.zip`). Its `build/assets/tailwindcss-*.js`, `oxide_parser_bg-*.wasm`, and `lightningcss_node-*.wasm`, plus `src/Core/Cache.php` (save) and `src/Core/Runtime.php` (serve), are the proven blueprint for browser compilation with a pure-PHP save/serve.

**Design doc:** `docs/superpowers/specs/2026-05-25-tailwind-browser-compile-design.md`

---

## File Structure

| File | Responsibility | Action |
|------|----------------|--------|
| `src/tailwind/engine.ts` | Wrap the Tailwind v4 browser engine: `compileTailwind(inputCss, content) → CSS`. | Create (Task 1) |
| `webpack.config.js` | Add the `tailwind-compiler` entry + WASM handling. | Modify (Task 1) |
| `includes/Tailwind/BinaryManager.php` | Add public `isShellAvailable()`. | Modify (Task 2) |
| `includes/Tailwind/Manager.php` | `isShellAvailable()`; add `shell_available`/`engine` to `getStatus()`. | Modify (Task 2) |
| `includes/Tailwind/BrowserCompiler.php` | `store(rawCss)`: scope (`Scoper`) + save (`Cache`). | Create (Task 3) |
| `tests/php/Tailwind/BrowserCompilerTest.php` | Unit test `store()`. | Create (Task 3) |
| `tests/php/bootstrap.php` | Add `wp_upload_dir`/`wp_mkdir_p` stubs for Cache tests. | Modify (Task 3) |
| `includes/Tailwind/AdminSettings.php` | Register + implement `get_compile_inputs` / `store_css` ajax handlers; engine-aware status + compile wiring. | Modify (Tasks 4, 6) |
| `src/tailwind/compiler.ts` | Orchestrate: fetch inputs → `compileTailwind` → POST store. Expose `window.ProtoBlocksTailwind.runBrowserCompile()`. | Create (Task 5) |
| `src/tailwind/__tests__/compiler.test.ts` | Jest test for orchestration (mock engine + ajax). | Create (Task 5) |
| `includes/Admin/Assets.php` | Enqueue `tailwind-compiler.js` on the settings page. | Modify (Task 6) |
| `docs/dynamic-control-options.md`-style doc | Document the browser-compile feature. | Create (Task 7) |

---

## Task 1 (SPIKE): Browser Tailwind engine module

**Why a spike:** the one real unknown is the exact Tailwind v4 browser API for compiling against a *provided content string* (not the live DOM). This task pins the packages, versions, and wiring, and delivers a concrete, fixed-signature module the rest of the plan consumes. WindPress's bundle is the reference.

**Deliverable contract (downstream tasks depend on this exact signature):**
```ts
// src/tailwind/engine.ts
export async function compileTailwind(inputCss: string, content: string): Promise<string>;
```
`inputCss` is the generated `input.css` (Tailwind v4 `@import` form). `content` is the aggregated block markup to scan for class candidates. Returns the compiled (unscoped) CSS.

**Files:**
- Create: `src/tailwind/engine.ts`
- Create (temporary, do not commit): `src/tailwind/__smoke__/smoke.html`, `src/tailwind/__smoke__/smoke.ts`
- Modify: `webpack.config.js`

- [ ] **Step 1: Try the high-level package first**

Run:
```bash
cd "/Users/gustavogomez/Documents/Projects/Protoblocks/Proto-Blocks"
npm install --save-dev @tailwindcss/browser@^4
```
Inspect its exports for an API that accepts an input CSS string + a content/candidate source (not just `document`):
```bash
node -e "const m=require('@tailwindcss/browser'); console.log(Object.keys(m))"
```
If it cleanly supports compiling from a provided CSS + content string, use it. If it only scans the live DOM (likely), proceed to Step 2 (the low-level path WindPress uses).

- [ ] **Step 2: If needed, install the low-level engine (WindPress's approach)**

Run:
```bash
npm install --save-dev tailwindcss@^4 @tailwindcss/oxide@^4
```
Reference the WindPress bundle to confirm the wiring:
```bash
unzip -o ~/Downloads/windpress.3.2.83.zip -d /tmp/windpress-inspect
ls /tmp/windpress-inspect/windpress/build/assets/*.wasm /tmp/windpress-inspect/windpress/build/assets/tailwindcss-*.js
```
The v4 browser flow is: use `tailwindcss`'s `compile(inputCss, { … })` to get a builder, extract class candidates from `content` (oxide WASM `scanFiles`/`scan`, or a candidate-token regex as a fallback), then `builder.build(candidates)` to produce CSS. Pin whichever combination produces correct output.

- [ ] **Step 3: Implement `src/tailwind/engine.ts` against whatever worked**

Implement the fixed-signature function. Example shape (adapt to the pinned API from Step 1/2):
```ts
// src/tailwind/engine.ts
// Compiles Tailwind v4 entirely in the browser. The exact engine wiring is
// pinned by the Task 1 spike; this module is the only place that touches it.
export async function compileTailwind(inputCss: string, content: string): Promise<string> {
    // ...pinned engine invocation: build candidates from `content`, compile `inputCss`...
    // return compiled CSS string
}
```

- [ ] **Step 4: Add the webpack entry + WASM handling**

In `webpack.config.js`, add to `entry`:
```js
    entry: {
        editor: path.resolve(__dirname, 'src/editor/index.tsx'),
        admin: path.resolve(__dirname, 'src/admin/index.ts'),
        'tailwind-compiler': path.resolve(__dirname, 'src/tailwind/compiler.ts'),
    },
```
Enable WebAssembly and ensure `.wasm` is emitted as an asset. Add (merging with the existing `module.rules` and adding `experiments`):
```js
    experiments: {
        ...(defaultConfig.experiments || {}),
        asyncWebAssembly: true,
    },
```
If the engine imports `.wasm?url` / `.wasm` directly, add a rule:
```js
            { test: /\.wasm$/, type: 'asset/resource' },
```
(The exact rule depends on how the pinned packages import their WASM — finalize here during the spike.)

- [ ] **Step 5: Prove it compiles in a real browser (temporary smoke)**

Create a throwaway `src/tailwind/__smoke__/smoke.ts` that calls `compileTailwind` and logs output length, and a `smoke.html`. Build and open it:
```bash
npm run build
```
Manually load the smoke output in a browser and confirm:
- Input `@import "tailwindcss/utilities.css";` + content `'<div class="flex text-red-500 p-4"></div>'`
- Output CSS contains rules for `.flex`, `.text-red-500`, `.p-4`.

Record the result in the commit message. **Delete the `__smoke__` directory before committing** (it must not ship).

- [ ] **Step 6: Commit (engine + build config + locked deps)**

```bash
git rm -r --cached src/tailwind/__smoke__ 2>/dev/null || true
rm -rf src/tailwind/__smoke__
git add src/tailwind/engine.ts webpack.config.js package.json package-lock.json
git commit -m "feat(tailwind): browser compile engine wrapper + wasm bundling"
```
In the commit body, record: the chosen package(s) + versions, the candidate-extraction method, and the smoke output (which utilities were produced).

---

## Task 2: Engine selection — `isShellAvailable()` + status

**Files:**
- Modify: `includes/Tailwind/BinaryManager.php` (add public `isShellAvailable()`)
- Modify: `includes/Tailwind/Manager.php` (`isShellAvailable()`; `getStatus()` additions)
- Test: `tests/php/Tailwind/BinaryManagerTest.php` (add one assertion)

- [ ] **Step 1: Write the failing test**

Append to `tests/php/Tailwind/BinaryManagerTest.php` (inside the class):
```php
    public function test_shell_is_available_on_this_test_environment(): void
    {
        // The dev/CI machine running PHPUnit has exec() enabled.
        $bm = new BinaryManager($this->binDir);
        $this->assertTrue($bm->isShellAvailable());
    }
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter BinaryManagerTest`
Expected: FAIL — "Call to undefined method ...::isShellAvailable()".

- [ ] **Step 3: Add `isShellAvailable()` to `BinaryManager`**

In `includes/Tailwind/BinaryManager.php`, make the existing private exec probe reusable by adding a public method that delegates to it (place it right after `isFunctional()`):
```php
    /**
     * Whether PHP can execute shell commands. False on managed hosts (e.g.
     * WP Engine) that disable exec/shell_exec — those must use browser compile.
     */
    public function isShellAvailable(): bool
    {
        return function_exists('exec') && $this->isExecAvailable();
    }
```

- [ ] **Step 4: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter BinaryManagerTest`
Expected: PASS (4 prior + 1 new = the file's tests all green).

- [ ] **Step 5: Add `Manager::isShellAvailable()` and expose engine in status**

In `includes/Tailwind/Manager.php`, add a delegating method near the other public methods:
```php
    /**
     * Whether the server can run the Tailwind CLI (has shell access).
     */
    public function isShellAvailable(): bool
    {
        return $this->getBinaryManager()->isShellAvailable();
    }
```
Then in `getStatus()` (where `$cliInstalled`/`$cliVersion` are computed), add the engine fields to the returned array:
```php
        $shellAvailable = $binaryManager->isShellAvailable();

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->getMode(),
            'disable_global_styles' => !empty($settings['disable_global_styles']),
            'shell_available' => $shellAvailable,
            'engine' => $shellAvailable ? 'cli' : 'browser',
            'cli_installed' => $cliInstalled,
            'cli_functional' => $cliInstalled && $cliVersion !== null,
            'cli_version' => $cliVersion,
            // ...existing remaining keys unchanged...
        ];
```
(Keep all existing keys after this; only add `shell_available` and `engine`.)

- [ ] **Step 6: Verify and commit**

Run: `php -l includes/Tailwind/Manager.php && php -l includes/Tailwind/BinaryManager.php && vendor/bin/phpunit`
Expected: no syntax errors; all tests pass.
```bash
git add includes/Tailwind/BinaryManager.php includes/Tailwind/Manager.php tests/php/Tailwind/BinaryManagerTest.php
git commit -m "feat(tailwind): expose shell availability and active compile engine"
```

---

## Task 3: `BrowserCompiler::store()` — scope + save (TDD)

The pure-PHP receiving end: take browser-produced raw CSS, scope it with the existing `Scoper`, and write it with the existing `Cache`. Fully unit-testable.

**Files:**
- Create: `includes/Tailwind/BrowserCompiler.php`
- Test: `tests/php/Tailwind/BrowserCompilerTest.php`
- Modify: `tests/php/bootstrap.php` (WP stubs needed by `Cache`)

- [ ] **Step 1: Add WP stubs the Cache needs, to `tests/php/bootstrap.php`**

Append:
```php
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        $base = sys_get_temp_dir() . '/pb-uploads';
        return ['basedir' => $base, 'baseurl' => 'http://example.test/uploads'];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/php/Tailwind/BrowserCompilerTest.php`:
```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BrowserCompiler;
use ProtoBlocks\Tailwind\Scoper;
use ProtoBlocks\Tailwind\Cache;

final class BrowserCompilerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pb-cache-' . uniqid() . '/';
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    public function test_store_scopes_and_writes_css(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $ok = $compiler->store('.flex{display:flex}');

        $this->assertTrue($ok);
        $saved = file_get_contents($this->cacheDir . 'tailwind.css');
        $this->assertNotFalse($saved);
        // Scoper prefixes selectors with the scope class.
        $this->assertStringContainsString('.' . Scoper::SCOPE_CLASS, $saved);
    }

    public function test_store_returns_false_for_empty_css(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $this->assertFalse($compiler->store('   '));
    }
}
```

- [ ] **Step 3: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter BrowserCompilerTest`
Expected: FAIL — "Class ProtoBlocks\Tailwind\BrowserCompiler not found".

- [ ] **Step 4: Implement `includes/Tailwind/BrowserCompiler.php`**

```php
<?php
/**
 * Browser Compiler - Receives browser-compiled Tailwind CSS and stores it.
 *
 * The shell-less counterpart to the CLI compile: the browser produces raw CSS,
 * this scopes it (reusing Scoper) and saves it (reusing Cache). No exec.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

class BrowserCompiler
{
    private Scoper $scoper;
    private Cache $cache;

    public function __construct(Scoper $scoper, Cache $cache)
    {
        $this->scoper = $scoper;
        $this->cache = $cache;
    }

    /**
     * Scope and persist browser-produced CSS.
     *
     * @return bool True when written; false when the input is empty or the
     *              write fails.
     */
    public function store(string $rawCss): bool
    {
        if (trim($rawCss) === '') {
            return false;
        }

        $scoped = $this->scoper->scopeCompiledCss($rawCss);

        return $this->cache->saveContent($scoped);
    }
}
```

- [ ] **Step 5: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter BrowserCompilerTest`
Expected: PASS — 2 tests.
Then run the full suite: `vendor/bin/phpunit` — all green.

- [ ] **Step 6: Commit**

```bash
git add includes/Tailwind/BrowserCompiler.php tests/php/Tailwind/BrowserCompilerTest.php tests/php/bootstrap.php
git commit -m "feat(tailwind): BrowserCompiler stores scoped browser-compiled CSS"
```

---

## Task 4: Admin-ajax endpoints — `get_compile_inputs` + `store_css`

Pure-PHP request/response. **Deviation from the spec, by design:** the spec described "REST endpoints," but the entire Tailwind subsystem uses WordPress **admin-ajax** (`wp_ajax_*` + a shared nonce + `verifyNonce()`, which already enforces `manage_options`). Following that established convention reuses the existing nonce/`ajaxurl`/`actionPrefix` infrastructure and adds no new wiring, while preserving the spec's intent exactly (pure-PHP in/out, `manage_options`-gated, identical data flow).

**Files:**
- Modify: `includes/Tailwind/AdminSettings.php`

- [ ] **Step 1: Register the two actions**

In the constructor's action block (next to the other `wp_ajax_` registrations, ~lines 46-55), add:
```php
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'get_compile_inputs', [$this, 'handleGetCompileInputs']);
        \add_action('wp_ajax_' . self::ACTION_PREFIX . 'store_css', [$this, 'handleStoreCss']);
```

- [ ] **Step 2: Implement `handleGetCompileInputs()`**

Add after `handleDownloadCli()`:
```php
    /**
     * Return the inputs the browser compiler needs: the generated input.css
     * and the aggregated block content to scan. Pure PHP (no exec).
     */
    public function handleGetCompileInputs(): void
    {
        $this->verifyNonce();

        $scanner = $this->manager->getScanner();
        $scanner->refresh();

        $content = $scanner->scanAllBlocks();
        // The browser scans `content` directly, so reference it by a stable
        // virtual name rather than a real file path.
        $inputCss = $this->manager->getConfigEditor()->generateInputCss('proto-blocks-content.html');

        \wp_send_json_success([
            'inputCss' => $inputCss,
            'content' => $content,
            'hash' => $scanner->getContentHash(),
        ]);
    }
```

- [ ] **Step 3: Implement `handleStoreCss()`**

```php
    /**
     * Receive browser-compiled CSS, scope + save it, and record the hash.
     */
    public function handleStoreCss(): void
    {
        $this->verifyNonce();

        // CSS can contain characters WP slashes on input; unslash before use.
        $css = isset($_POST['css']) ? \wp_unslash((string) $_POST['css']) : '';
        $hash = isset($_POST['hash']) ? \sanitize_text_field((string) $_POST['hash']) : '';

        $compiler = new \ProtoBlocks\Tailwind\BrowserCompiler(
            $this->manager->getScoper(),
            $this->manager->getCache()
        );

        if (!$compiler->store($css)) {
            \wp_send_json_error([
                'message' => \__('No CSS was produced by the browser compiler.', 'proto-blocks'),
            ]);
        }

        if ($hash !== '') {
            $this->manager->getCache()->saveHash($hash);
        }
        $this->manager->updateSettings(['last_compiled' => time()]);

        $cache = $this->manager->getCache();
        \wp_send_json_success([
            'message' => \__('Tailwind CSS compiled in the browser and saved.', 'proto-blocks'),
            'css_size' => $cache->getSize(),
            'css_size_formatted' => $cache->getFormattedSize(),
        ]);
    }
```

- [ ] **Step 4: Verify**

Run: `php -l includes/Tailwind/AdminSettings.php`
Expected: "No syntax errors detected".
Confirm `Manager` exposes `getScoper()`, `getCache()`, `getScanner()`, `getConfigEditor()`, `updateSettings()` (it does — see `Manager.php`).

- [ ] **Step 5: Commit**

```bash
git add includes/Tailwind/AdminSettings.php
git commit -m "feat(tailwind): admin-ajax endpoints for browser compile inputs and storage"
```

---

## Task 5: Browser orchestration `compiler.ts` (TDD, Jest)

Orchestrates: fetch inputs → `compileTailwind` → POST stored CSS. Exposed as `window.ProtoBlocksTailwind.runBrowserCompile()` so the admin page's inline script can trigger it.

**Files:**
- Create: `src/tailwind/compiler.ts`
- Test: `src/tailwind/__tests__/compiler.test.ts`

- [ ] **Step 1: Write the failing test**

Create `src/tailwind/__tests__/compiler.test.ts`:
```ts
import { runBrowserCompile } from '../compiler';
import { compileTailwind } from '../engine';

jest.mock('../engine');

const mockedCompile = compileTailwind as jest.Mock;

describe('runBrowserCompile', () => {
    beforeEach(() => {
        mockedCompile.mockReset();
    });

    it('fetches inputs, compiles, and posts the resulting CSS', async () => {
        mockedCompile.mockResolvedValue('.flex{display:flex}');

        const post = jest
            .fn()
            // 1st call: get_compile_inputs
            .mockResolvedValueOnce({
                success: true,
                data: { inputCss: '@import "x";', content: '<div class="flex"></div>', hash: 'abc' },
            })
            // 2nd call: store_css
            .mockResolvedValueOnce({
                success: true,
                data: { message: 'ok', css_size_formatted: '1 KB' },
            });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(post).toHaveBeenNthCalledWith(1, '/admin-ajax.php', {
            action: 'proto_blocks_tailwind_get_compile_inputs',
            nonce: 'n',
        });
        expect(mockedCompile).toHaveBeenCalledWith('@import "x";', '<div class="flex"></div>');
        // 2nd post sends the compiled css + hash to store_css
        expect(post.mock.calls[1][1]).toMatchObject({
            action: 'proto_blocks_tailwind_store_css',
            nonce: 'n',
            css: '.flex{display:flex}',
            hash: 'abc',
        });
        expect(result.success).toBe(true);
    });

    it('reports failure when input fetch fails', async () => {
        const post = jest.fn().mockResolvedValueOnce({ success: false, data: { message: 'denied' } });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(result.success).toBe(false);
        expect(mockedCompile).not.toHaveBeenCalled();
    });
});
```

- [ ] **Step 2: Run it, verify it fails**

Run: `npm run test:js -- compiler`
Expected: FAIL — cannot find module `../compiler`.

- [ ] **Step 3: Implement `src/tailwind/compiler.ts`**

```ts
/**
 * Browser-side Tailwind compile orchestration for Proto-Blocks.
 *
 * Fetches the compile inputs from the server, compiles Tailwind in the
 * browser, and posts the resulting CSS back to be scoped + saved. Used on
 * hosts without shell access (e.g. WP Engine).
 */

import { compileTailwind } from './engine';

interface AjaxResponse<T = unknown> {
    success: boolean;
    data: T;
}

type PostFn = (url: string, data: Record<string, string>) => Promise<AjaxResponse<any>>;

export interface RunBrowserCompileDeps {
    ajaxUrl: string;
    actionPrefix: string;
    nonce: string;
    post: PostFn;
}

export interface RunBrowserCompileResult {
    success: boolean;
    message: string;
    cssSizeFormatted?: string;
}

export async function runBrowserCompile(
    deps: RunBrowserCompileDeps
): Promise<RunBrowserCompileResult> {
    const { ajaxUrl, actionPrefix, nonce, post } = deps;

    const inputs = await post(ajaxUrl, {
        action: actionPrefix + 'get_compile_inputs',
        nonce,
    });
    if (!inputs.success) {
        return { success: false, message: inputs.data?.message || 'Failed to load compile inputs.' };
    }

    const { inputCss, content, hash } = inputs.data;
    const css = await compileTailwind(inputCss, content);

    const stored = await post(ajaxUrl, {
        action: actionPrefix + 'store_css',
        nonce,
        css,
        hash: hash || '',
    });
    if (!stored.success) {
        return { success: false, message: stored.data?.message || 'Failed to save compiled CSS.' };
    }

    return {
        success: true,
        message: stored.data?.message || 'Compiled.',
        cssSizeFormatted: stored.data?.css_size_formatted,
    };
}

// Browser entry: expose for the admin page's inline script, with a jQuery-backed
// post adapter (jQuery is present on wp-admin).
declare global {
    interface Window {
        jQuery: any;
        ProtoBlocksTailwind?: { runBrowserCompile: (d: Omit<RunBrowserCompileDeps, 'post'>) => Promise<RunBrowserCompileResult> };
    }
}

if (typeof window !== 'undefined') {
    window.ProtoBlocksTailwind = {
        runBrowserCompile: (d) =>
            runBrowserCompile({
                ...d,
                post: (url, data) =>
                    new Promise((resolve, reject) =>
                        window.jQuery.post(url, data).done(resolve).fail(reject)
                    ),
            }),
    };
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `npm run test:js -- compiler`
Expected: PASS — 2 tests.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: "compiled successfully"; `assets/js/tailwind-compiler.js` produced. (`assets/js/` is gitignored — do not commit build output.)

- [ ] **Step 6: Commit (source + test only)**

```bash
git add src/tailwind/compiler.ts src/tailwind/__tests__/compiler.test.ts
git commit -m "feat(tailwind): browser compile orchestration with unit tests"
```

---

## Task 6: Admin UI — enqueue bundle, engine-aware status, wire the button

**Files:**
- Modify: `includes/Admin/Assets.php` (enqueue `tailwind-compiler.js` on the settings page)
- Modify: `includes/Tailwind/AdminSettings.php` (status + Compile button JS)

- [ ] **Step 1: Enqueue the compiler bundle on the settings page**

In `includes/Admin/Assets.php`, where `proto-blocks-admin` is enqueued for the admin/settings screen (~line 213), add after it:
```php
        wp_enqueue_script(
            'proto-blocks-tailwind-compiler',
            PROTO_BLOCKS_URL . 'assets/js/tailwind-compiler.js',
            ['jquery'],
            PROTO_BLOCKS_VERSION,
            true
        );
```
(If the admin script enqueue is gated to a specific `hook_suffix`/screen, place this inside that same guard so it only loads on the Proto-Blocks settings screen.)

- [ ] **Step 2: Make the CLI Status panel engine-aware**

In `includes/Tailwind/AdminSettings.php`, replace the CLI-status `<div id="cli-status">` block so it shows the browser engine when there's no shell:
```php
                                    <div class="pb-flex pb-items-center pb-gap-1" id="cli-status">
                                        <?php if ($status['engine'] === 'browser'): ?>
                                            <span class="material-icons-outlined pb-text-green-600 pb-text-lg">cloud_done</span>
                                            <span class="pb-text-sm"><?php \esc_html_e('Browser compiler', 'proto-blocks'); ?></span>
                                        <?php elseif ($status['cli_functional']): ?>
                                            <span class="material-icons-outlined pb-text-green-600 pb-text-lg">check_circle</span>
                                            <span class="pb-text-sm"><?php printf(\esc_html__('Server CLI v%s', 'proto-blocks'), \esc_html($status['cli_version'])); ?></span>
                                        <?php elseif ($status['cli_installed']): ?>
                                            <span class="material-icons-outlined pb-text-orange-500 pb-text-lg">warning</span>
                                            <span class="pb-text-sm pb-text-orange-600"><?php \esc_html_e('Installed but not runnable — re-download', 'proto-blocks'); ?></span>
                                        <?php else: ?>
                                            <span class="material-icons-outlined pb-text-red-600 pb-text-lg">error</span>
                                            <span class="pb-text-sm pb-text-red-600"><?php \esc_html_e('Not installed', 'proto-blocks'); ?></span>
                                        <?php endif; ?>
                                    </div>
```

- [ ] **Step 3: Hide the binary download button in browser mode, enable Compile per engine**

In the Actions block, wrap the `#download-cli` button so it only shows when a shell exists (the binary is useless without one):
```php
                                <?php if ($status['shell_available']): ?>
                                <button type="button" id="download-cli" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors">
                                    <span class="material-icons-outlined pb-text-sm">download</span>
                                    <?php
                                    if ($status['cli_functional']) {
                                        \esc_html_e('Re-download / Update CLI', 'proto-blocks');
                                    } else {
                                        \esc_html_e('Download Tailwind CLI', 'proto-blocks');
                                    }
                                    ?>
                                </button>
                                <?php endif; ?>
```
Change the Compile button's `disabled` gate so it's enabled in browser mode OR when the CLI is functional:
```php
                                <button type="button" id="compile-tailwind" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors disabled:pb-opacity-50 disabled:pb-cursor-not-allowed" <?php echo ($status['engine'] === 'browser' || $status['cli_functional']) ? '' : 'disabled'; ?>>
```

- [ ] **Step 4: Route the Compile button to the browser path when in browser mode**

In the inline script, expose the engine and branch the existing compile click handler. Just after `const actionPrefix = '<?php echo self::ACTION_PREFIX; ?>';` add:
```php
            const compileEngine = '<?php echo \esc_js($status['engine']); ?>';
```
Replace the body of the `$('#compile-tailwind').on('click', …)` handler so browser mode calls the bundle:
```js
            $('#compile-tailwind').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true);
                $btn.find('.material-icons-outlined').addClass('spin');

                if (compileEngine === 'browser') {
                    window.ProtoBlocksTailwind.runBrowserCompile({
                        ajaxUrl: ajaxurl,
                        actionPrefix: actionPrefix,
                        nonce: nonce,
                    }).then(function(result) {
                        showMessage(result.message, result.success ? 'success' : 'error');
                        if (result.success) {
                            location.reload();
                        } else {
                            $btn.prop('disabled', false);
                            $btn.find('.material-icons-outlined').removeClass('spin');
                        }
                    }).catch(function(err) {
                        showMessage(String(err && err.message ? err.message : err), 'error');
                        $btn.prop('disabled', false);
                        $btn.find('.material-icons-outlined').removeClass('spin');
                    });
                    return;
                }

                $.post(ajaxurl, {
                    action: actionPrefix + 'compile',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        if (response.data.css_size_formatted) {
                            $('#css-size').text(response.data.css_size_formatted);
                        }
                        location.reload();
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                    $btn.prop('disabled', false);
                    $btn.find('.material-icons-outlined').removeClass('spin');
                });
            });
```
(Match the existing handler's spinner class — confirm whether it uses `.dashicons`/`.material-icons-outlined` and keep it consistent.)

- [ ] **Step 5: Build, verify, manual smoke**

Run: `npm run build && php -l includes/Tailwind/AdminSettings.php && php -l includes/Admin/Assets.php`
Expected: build succeeds; no PHP syntax errors.
On a shell-capable local install, confirm the page still shows "Server CLI vX" and the existing Compile path works (regression check).

- [ ] **Step 6: Commit**

```bash
git add includes/Admin/Assets.php includes/Tailwind/AdminSettings.php
git commit -m "feat(tailwind): engine-aware settings UI and browser compile trigger"
```

---

## Task 7: Docs + WP Engine end-to-end verification

**Files:**
- Create: `docs/tailwind-browser-compile.md`
- Modify: `README.md` (Tailwind section: note browser compile on shell-less hosts)

- [ ] **Step 1: Write `docs/tailwind-browser-compile.md`**

Document: the two engines and how Proto-Blocks auto-selects (`shell_available`); that on managed hosts (WP Engine) it compiles in the browser with no setup; the admin flow (open settings → "Compile CSS" → it loads the engine, compiles, saves); that the result is served identically to CLI output; and the `manage_options`/nonce protection on the endpoints. Note the out-of-scope items (no live-editor compiling yet).

- [ ] **Step 2: Add a short README note**

In the README Tailwind section, add a sentence: on hosts without shell access (e.g. WP Engine), Proto-Blocks compiles Tailwind in the browser automatically — no binary, no build step — link to `docs/tailwind-browser-compile.md`.

- [ ] **Step 3: Manual end-to-end on WP Engine (NOT runnable in this repo — record results)**

On a real WP Engine install with `useTailwind` blocks:
1. Open Proto-Blocks → Tailwind settings. Confirm CLI Status shows **"Browser compiler"** and there is **no** "exec required" error and **no** binary-download button.
2. Click **Compile CSS**. Confirm progress, then a success message and a non-zero **CSS Size**.
3. View a page using a Tailwind block on the front end; confirm the utilities render (the cached `tailwind.css` is enqueued).
4. Regression: on a shell-capable environment, confirm it still shows "Server CLI vX" and uses the CLI compile.

Record pass/fail with notes in the commit/PR. Do not claim success without observing steps 1–3.

- [ ] **Step 4: Commit**

```bash
git add docs/tailwind-browser-compile.md README.md
git commit -m "docs(tailwind): document environment-aware browser compile"
```

---

## Final Verification

- [ ] `vendor/bin/phpunit` — all green.
- [ ] `npm run test:js` — all green.
- [ ] `npm run build` — compiled successfully; `assets/js/tailwind-compiler.js` present.
- [ ] Manual WP Engine e2e (Task 7, steps 1–3) observed passing.
- [ ] Regression: shell-capable env still uses CLI compile (Task 7, step 4).

## Notes / Deferred (out of scope)

- Live editor "Play Observer"-style compilation as classes appear.
- CDN-loaded Tailwind plugins/configs (`@plugin`/`@config`).
- Front-end on-the-fly compilation; source maps.
- The candidate-extraction fidelity in the browser depends on the engine pinned in Task 1; if a regex fallback is used instead of oxide WASM, note its limitations in that task's commit.
