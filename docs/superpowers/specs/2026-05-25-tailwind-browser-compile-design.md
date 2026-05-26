# Environment-Aware Tailwind Compile (Server CLI ↔ Browser) — Design

**Date:** 2026-05-25
**Status:** Approved (pending spec review)

## Problem

Proto-Blocks compiles Tailwind by downloading the standalone Tailwind CLI binary and running it with PHP `exec()`. Managed hosts — confirmed on **WP Engine** — disable every PHP shell-execution function (`exec`, `shell_exec`, `proc_open`, `popen`, `system`) as a security policy that cannot be changed. Consequences on such hosts:

- The binary can be downloaded (HTTP), but never run → version reads as "v?", and `isFunctional()` is false.
- Compilation always fails → `CSS Size: 0 B`.
- The settings page surfaces *"PHP exec() function is not available. Shell access is required for Tailwind compilation."* and there is no working path forward.

There is no pure-PHP Tailwind compiler, and the `npx` fallback also needs a shell, so **no server-side approach can work on these hosts.**

## Reference: how WindPress solves it

WindPress (3.2.83) was inspected. Its entire `src/` contains **zero** `exec`/`shell_exec`/`proc_open` calls. It compiles **in the browser**:

- Bundles the Tailwind v4 engine as JS plus two WASM modules: `oxide_parser_bg.wasm` (candidate scanning/parsing) and `lightningcss_node.wasm` (optimize/minify).
- The browser compiles and **POSTs the finished CSS** to a REST endpoint (`Cache::save_cache()` → `file_put_contents`).
- The front end serves the cached file via a plain `wp_register_style` with a `filemtime` version (`Runtime.php`).

This server side (save + serve, pure PHP) is architecturally identical to Proto-Blocks' existing `Cache` + `Assets::enqueueTailwindCss()`.

## Goal

Make Tailwind compilation work on every host, including shell-less managed hosts, **without** a manual build/upload step, by adding an in-browser compile engine. Preserve the existing, faster server-side CLI compile where a shell is available.

## Approach (decided)

- **Direction:** Browser-compile, WindPress-style.
- **CLI path:** Auto-select per environment — keep the standalone-CLI compile when shell access exists; fall back to browser compile when it is blocked. Both paths coexist.

## Architecture

The existing 5-step compile pipeline (`Compiler::compile()`, `Compiler.php:70–115`) is preserved; only **step 3 (run the engine)** varies by environment:

| Step | Today (CLI) | Browser path | Component |
|------|-------------|--------------|-----------|
| 1. Gather content | `Scanner::writeContentFile()` | `Scanner::scanAllBlocks()` (string) | `Scanner` (reused) |
| 2. Build input CSS | `ConfigEditor::generateInputCss()` | same | `ConfigEditor` (reused) |
| 3. **Run engine** | `exec(binary …)` | **browser engine** | NEW (browser) |
| 4. Scope output | `Scoper::scopeCompiledCss()` | same (server-side, on store) | `Scoper` (reused) |
| 5. Save + hash | `Cache::saveContent()` + `saveHash()` | same | `Cache` (reused) |

**Engine selection:** a new `Manager::isShellAvailable(): bool` (derived from `BinaryManager`'s existing exec probe). When true → the current server compile runs unchanged. When false → the "Compile CSS" action runs the browser compile.

### Browser-compile data flow (no `exec` anywhere)

1. **GET** `proto-blocks/v1/tailwind/compile-inputs` → `{ inputCss, content, hash }`.
   PHP assembles these with `ConfigEditor::generateInputCss()` + `Scanner::scanAllBlocks()` + `Scanner::getContentHash()`. Pure PHP.
2. **Browser** loads the bundled Tailwind v4 engine (tailwindcss JS + `oxide` WASM for candidate extraction + `lightningcss` WASM for minify), compiles `inputCss` against the candidates extracted from `content` → raw CSS.
3. **POST** `proto-blocks/v1/tailwind/store` `{ css, hash }` → PHP runs `Scoper::scopeCompiledCss()` then `Cache::saveContent()` + `Cache::saveHash()`. Pure PHP.

Both routes are admin-settings operations, so they require the **`manage_options`** capability and a REST nonce, consistent with the existing Tailwind admin page (which gates its `wp_ajax_` handlers via `verifyNonce()` on an admin-only screen).
4. Front end serves the cached file via the existing `Assets::enqueueTailwindCss()`. Unchanged.

Scoping is applied **server-side on store** (reusing `Scoper`) so the `.proto-blocks-scope` behavior is identical across both engines and the browser never needs the scoping logic.

### Where it runs (v1)

The existing **"Compile CSS"** button. When shell exists it calls today's server compile AJAX; when it does not, the same button drives the browser compile (load engine → GET inputs → compile → POST store → reload status). Because inputs come from `Scanner` (all discovered block templates), coverage is complete — it does not depend on what is currently rendered in any DOM.

### Bundling

A new `wp-scripts` entry (e.g. `src/tailwind/compiler.ts`) bundles the engine + WASM into `build/`. It is enqueued only on the Proto-Blocks Tailwind settings page (and, in a future iteration, the block editor). The WASM files ship inside the plugin; no CDN dependency for the core engine.

### Admin UI changes (`Tailwind/AdminSettings.php`)

- Status panel shows the **active engine**: "Server (Tailwind CLI v{version})" when shell-capable, or **"Browser compiler"** when not.
- In browser mode, hide the binary download / version / "exec required" elements (the dead-end from the current bug) and show compile progress: *loading engine → compiling → saving*.
- "Compile CSS" is enabled whenever the active engine is usable (CLI functional, or browser engine available), not gated on `cli_functional` alone.

## Components

| Component | Type | Responsibility |
|-----------|------|----------------|
| `Manager::isShellAvailable()` | PHP (new method) | Single source of truth for engine selection. |
| REST `GET /tailwind/compile-inputs` | PHP (new) | Return `{ inputCss, content, hash }`. Pure PHP. |
| REST `POST /tailwind/store` | PHP (new) | Scope + save browser-produced CSS. Pure PHP. |
| `src/tailwind/compiler.ts` | JS (new) | Load engine, compile inputs → CSS, POST to store. |
| Bundled engine + WASM | asset (new) | Tailwind v4 browser compile. |
| `AdminSettings` status/actions | PHP (modified) | Engine-aware status + progress; remove dead-end. |
| `Scanner`, `ConfigEditor`, `Scoper`, `Cache` | PHP (reused) | Unchanged. |
| `BinaryManager` + CLI compile | PHP (reused) | Unchanged; used when shell available. |

## The key risk / first task

The exact Tailwind-v4 browser-engine API for compiling against a **provided content string** (rather than scanning the live DOM) is the one unknown. WindPress proves it is achievable by hand-bundling tailwindcss + `oxide` (WASM) + `lightningcss` (WASM) and wiring `compile(inputCss).build(candidates)` with oxide-extracted candidates.

**Therefore the first implementation task is a focused spike** to pin the exact packages/versions and the compile/candidate-extraction wiring (using WindPress's bundle as the reference), producing a minimal "input CSS + content string → CSS string" proof in the browser, before any UI is built around it.

## Testing

- **PHP unit tests** (existing PHPUnit harness): the two new REST handlers — input assembly returns the expected shape; store applies `Scoper` then `Cache::saveContent` (verify scoping is applied and the file is written).
- **JS unit test**: the `compiler.ts` wrapper with a mocked engine — verifies it requests inputs, calls the engine, and POSTs the result.
- **Manual e2e**: on a real WP Engine install — Compile CSS produces a non-empty `tailwind.css`, the front end serves it, and the "exec required" dead-end is gone. Also verify a shell-capable env still uses the CLI path unchanged.

## Out of scope (YAGNI for v1)

- Live editor "Play Observer"-style compiling as classes appear.
- CDN-loaded Tailwind plugins/configs (`@plugin` / `@config` from esm.sh).
- Front-end on-the-fly compilation.
- Source maps.

These can be layered on later without changing the v1 architecture.
