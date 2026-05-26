# Environment-Aware Tailwind Compilation

## Overview

Proto-Blocks compiles Tailwind CSS using one of two engines, selected automatically based on the server environment. The motivation: managed WordPress hosts such as WP Engine disable PHP shell functions (`exec`, `shell_exec`, `proc_open`, etc.), which makes it impossible to run the standalone Tailwind CLI binary. To provide the same compile-and-cache workflow on those hosts, Proto-Blocks includes a **browser compile** engine that works purely in-browser — no binary, no shell access required.

---

## How the Engine Is Chosen

`Manager::isShellAvailable()` probes for shell access at runtime. The result is exposed via `Manager::getStatus()` which returns:

```php
[
    'shell_available' => bool,
    'engine'          => 'cli' | 'browser',
    // ...
]
```

| `shell_available` | `engine` | Admin status panel shows |
|-------------------|----------|--------------------------|
| `true` | `'cli'` | **Server CLI v{version}** |
| `false` | `'browser'` | **Browser compiler** |

The engine selection is fully automatic — no configuration is needed on either type of host.

---

## Browser Compile Flow (no-exec path)

This path activates when the host has no shell access.

**User action:** Open **Proto-Blocks → Tailwind Settings** and click **Compile CSS**.

**Behind the scenes:**

1. The Tailwind settings page enqueues `assets/js/tailwind-compiler.js`, a bundle (~329 KiB) that includes the Tailwind v4 engine. This script only loads on the Tailwind settings screen.
2. Clicking **Compile CSS** calls `window.ProtoBlocksTailwind.runBrowserCompile()`, which:
   - **GET** `admin-ajax.php?action=proto_blocks_get_compile_inputs` — the server responds with `{ inputCss, content, hash }` (the input CSS and scanned block content).
   - Compiles in the browser via `compileTailwind(inputCss, content)` using the bundled engine.
   - **POST** `admin-ajax.php?action=proto_blocks_store_css` with `{ css, hash }` — sends the compiled CSS back to the server.
3. The server runs the CSS through `Scoper` (scopes all rules to `.proto-blocks-scope`) and saves the result via `Cache::saveContent`. No binary, no shell.
4. The front end enqueues the cached `tailwind.css` exactly as it does in CLI mode — the output format is identical.

---

## CLI Compile (unchanged)

On hosts where shell access is available, Proto-Blocks uses the standalone Tailwind CLI binary. The binary is auto-downloaded if it is missing. The browser path is the fallback only; the CLI path behavior is unchanged.

To force a CLI recompile manually:

```bash
wp eval 'ProtoBlocks\Core\Plugin::getInstance()->getTailwindManager()->compile();'
```

---

## Security

Both admin-ajax endpoints (`get_compile_inputs` and `store_css`) are gated by `verifyNonce()`, which enforces:

- The `manage_options` WordPress capability (administrators only).
- A valid nonce tied to the current admin session.

Unauthenticated or non-administrator requests receive an error response and no CSS is read or written.

---

## Limitations

- **Output is not minified.** The browser-compiled CSS is unminified. Minification is future work.
- **No live-editor compiling.** The browser engine does not compile Tailwind as classes are typed in the editor. A full compile is triggered only by clicking **Compile CSS**.
- **No CDN-loaded `@plugin` or `@config`.** The bundled engine does not support externally loaded Tailwind plugins or config files pulled from a CDN.
- **No source maps.** The browser compile output does not include source maps.
- **Bundle size.** The Tailwind v4 engine bundle is ~329 KiB and only loads on the Tailwind settings screen (not on the front end).

---

## Manual Verification

> **Note:** The steps below require a live WordPress install. They are documentation only and are not run as part of the build.

1. **Browser engine on a shell-less host (e.g. WP Engine):**
   Open **Proto-Blocks → Tailwind Settings**. Confirm that the CLI Status panel shows **"Browser compiler"**, that there is no "exec required" error message, and that there is no binary-download button.

2. **Successful browser compile:**
   Click **Compile CSS**. Confirm the button shows a busy spinner during compilation, then transitions to a success message with a non-zero **CSS Size** value.

3. **Front-end rendering:**
   Load a page that uses a Tailwind-styled Proto Block. Confirm that utility classes render correctly — the cached `tailwind.css` file should be enqueued and applied.

4. **CLI regression on a shell-capable host:**
   On an environment with shell access, open **Proto-Blocks → Tailwind Settings** and confirm the status shows **"Server CLI v{version}"**. Trigger a compile and confirm it completes successfully via the CLI path.
