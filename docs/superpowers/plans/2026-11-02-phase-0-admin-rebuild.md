# Phase 0: Admin Rebuild Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every PHP-rendered Proto-Blocks admin screen with one React application on the new stack, with no loss of function, so Phases 1–3 have a shell, a REST pattern and a form stack to mount on.

**Architecture:** Each existing `admin.php?page=proto-blocks*` menu page keeps its slug and capability but renders only a mount element plus a bootstrap object; one webpack entry (`src/admin/index.tsx`) mounts a screen chosen from that bootstrap. Screens talk to a new `AdminRestController` under `proto-blocks/v1/admin/*` that wraps the existing `AdminPage`, `Tailwind\Manager`, `PreviewCapture` and `Cache` logic, which is kept; only the HTML rendering, the classic form POST handlers and the per-control `admin-ajax` actions go. The two AJAX actions the browser compiler uses stay, because the editor and front-end auto-compile path shares them.

**Tech Stack:** PHP 8.1, WordPress 6.3+, `@wordpress/scripts` 37 (webpack, Babel, Jest 30), React 18 (externalised to `wp-element`), TypeScript, Tailwind CSS 4 via `@tailwindcss/postcss`, shadcn/ui on Radix, react-hook-form + zod, `@wordpress/api-fetch`, `html2canvas-pro`, `@testing-library/react`, PHPUnit 10 without WordPress.

**Spec:** `docs/superpowers/specs/2026-11-02-post-types-fields-settings-design.md` (sections "Constraints", "4. The admin UI", "10. Phases": Phase 0).

## Global Constraints

- PHP 8.1+; align the plugin header `Requires PHP` with `composer.json` (`^8.1`). WordPress floor stays 6.3 in this phase.
- Capability `manage_options` on every admin screen and every `admin/*` REST route; file-writing routes also require `AdminPage::fileModsAllowed()`.
- Tailwind utilities must never touch core admin styles: no global preflight; base styles scoped to `#proto-blocks-admin`; Radix portals render inside `#proto-blocks-admin-portal`.
- Tailwind v3 (`tailwindcss ^3.4`, `tailwind.config.js`, `autoprefixer`) is removed; the whole repo uses one `tailwindcss` ^4 dependency.
- No database writes beyond the options the current screens already write: `proto_blocks_example_blocks`, `proto_blocks_category_name`, `proto_blocks_component_style`, `proto_blocks_wizard_completed`, `proto_blocks_tailwind`.
- Every user-visible string goes through `__()` on the PHP side or `@wordpress/i18n` on the JS side, text domain `proto-blocks`.
- REST errors follow `RestAPI`: `WP_Error('proto_blocks_<code>', __(...), ['status' => N])`; exception text only when `PROTO_BLOCKS_DEBUG`.
- Commit messages: `<type>(<scope>): <subject>`, no attribution lines. The repo works on `main`; the release workflow bumps versions from `feat`/`fix` subjects.
- PHPUnit (`composer test`) and Jest (`npm run test:js`) must pass after every task.

## Review Focus

1. **File modifications disabled** (`DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT`): every install, remove, download, create-file and capture control is disabled with one banner saying why, and the matching routes answer 403 `proto_blocks_file_mods`. Tests: Task 3 (`writePermission`), Task 4 (banner and disabled buttons), Task 6 (capture buttons).
2. **Example blocks locked by `PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG`:** the toggle is disabled with the locked notice and the POST answers 409 `proto_blocks_locked`. Tests: Task 4.
3. **Changing the Tailwind engine or downloading the CLI changes resolved status:** the screen refetches status and the CLI status card shows the right one of six states. Tests: Task 5.
4. **Browser compile from the new screen** calls the existing `window.ProtoBlocksTailwind.runBrowserCompile` with the Tailwind AJAX nonce, and the editor/front-end auto-compile keeps working because `get_compile_inputs` and `store_css` stay registered. Tests: Task 5.
5. **A failing block in a batch capture** does not stop the batch; the summary counts captured and failed; a REST rejection message appears on the row. Tests: Task 6.

---

## File structure

**PHP (create)**
- `includes/Admin/AdminApp.php` — renders the mount element and builds the bootstrap object for a screen.
- `includes/API/AdminRestController.php` — all `admin/*` routes, permission callbacks, and the pure helpers the routes use.
- `tests/php/Admin/wp-stubs.php` — function_exists-guarded stubs (`current_user_can`, `rest_url`, `wp_create_nonce`, `WP_Error`, `WP_REST_Request`, `WP_REST_Response`, `register_rest_route`), reading `$GLOBALS['pb_test_caps']` like `tests/php/Security/wp-stubs.php`.
- `tests/php/Admin/AdminAppTest.php`, `tests/php/API/AdminRestControllerTest.php`.

**PHP (modify)**
- `includes/Admin/AdminPage.php` — delete `renderPage`, `renderSettingsPage`, `renderSystemStatusPage`, `printSkippedDemoBlocksNotice` and the inline HTML; make `removeDemoBlocks()` public; add `dashboardData(): array` and `installPath(): string`. Keep every other method.
- `includes/Admin/SetupWizard.php` — delete `renderWizard` and `handleFormSubmission`; keep `register`, `maybeRedirectToWizard`, `hideWizardMenuItem`, `normalizeComponentStyle`; add `saveStyle(string $style, string $categoryName): void`, `installDemos(): array`, `complete(): void`, `data(): array`.
- `includes/Admin/PreviewCapture.php` — `route()` renders the mount instead of `renderAdminUi`; delete `renderAdminUi` and `enqueueAssets`; make `collectBlocks()` public. Keep the render endpoint and the REST route.
- `includes/Tailwind/AdminSettings.php` — delete `render()` and every handler except `handleGetCompileInputs`, `handleStoreCss`, `verifyNonce`; add `public const KEPT_AJAX_ACTIONS = ['get_compile_inputs', 'store_css']` and register only those.
- `includes/Admin/Assets.php` — `enqueueAdminAssets` enqueues `proto-blocks-admin` (script, from `assets/js/admin.asset.php` deps) and `assets/css/admin.css`; drops Google Fonts and Material Icons; keeps `enqueueTailwindCompiler()`.
- `includes/Core/Plugin.php` — construct `AdminApp` and `AdminRestController`; hook `registerRoutes` on `rest_api_init`.
- `proto-blocks.php` — `Requires PHP: 8.1`.

**JS/CSS (create)** under `src/admin/`
- `index.tsx` (mount), `app.tsx` (screen switch and providers), `bootstrap.ts` (typed `window.protoBlocksAdmin`), `api.ts` (fetch wrapper), `styles/admin.css` (Tailwind 4 + shadcn tokens, scoped).
- `components/ui/*` — shadcn/ui components added by the CLI: `button`, `card`, `badge`, `switch`, `radio-group`, `input`, `label`, `table`, `dialog`, `alert`, `tabs`, `tooltip`, `skeleton`, `sonner`, `checkbox`, `separator`.
- `components/shell/{PageHeader,StatCard,ReadOnlyBanner,ConfirmDialog,PortalProvider,ScreenError}.tsx`.
- `screens/{SystemScreen,DashboardScreen,TailwindScreen,PreviewsScreen,WizardScreen}.tsx`.
- `lib/capture.ts` — the preview capture sequencer.
- `__tests__/` — one test file per screen plus `api.test.ts`, `capture.test.ts`, with `__tests__/helpers.tsx` (`mockBootstrap`, `mockApi`).

**Build (modify/create)**
- `package.json`, `postcss.config.js` (create), `components.json` (shadcn, create), `webpack.config.js`, `jest.config.js`, `jest.setup.ts` (create), `tsconfig.json` (`jsx: react-jsx`).

**Delete**
- `tailwind.config.js`, `src/admin/admin.css`, `assets/admin/preview-capture.js`, `assets/vendor/html2canvas-pro.min.js`, `assets/css/preview-capture.css`.

**CI (create/modify)**
- `.github/workflows/test.yml` (create), `.github/workflows/release.yml` (add a `test` job the release depends on).

---

### Task 1: Toolchain — Tailwind 4, shadcn/ui, React admin entry, Jest DOM

**Files:**
- Modify: `package.json`, `webpack.config.js`, `jest.config.js`, `tsconfig.json`, `src/tailwind/compiler.ts`, `src/tailwind/__tests__/compiler.test.ts`
- Create: `postcss.config.js`, `components.json`, `jest.setup.ts`, `src/admin/styles/admin.css`, `src/admin/index.tsx`, `src/admin/components/ui/button.tsx` (via CLI), `src/admin/__tests__/toolchain.test.tsx`
- Delete: `tailwind.config.js`, `src/admin/admin.css`

**Interfaces:**
- Produces: webpack entry `admin` → `assets/js/admin.js` + `assets/js/admin.asset.php` + `assets/css/admin.css`; path alias `@/` → `src/`; shadcn components importable as `@/admin/components/ui/<name>`; `cn()` in `src/admin/lib/utils.ts`.

- [ ] **Step 1: Write the failing Jest test**

`src/admin/__tests__/toolchain.test.tsx`:
```tsx
import { render, screen } from '@testing-library/react';
import { Button } from '@/admin/components/ui/button';

test('shadcn Button renders with variant classes', () => {
  render(<Button variant="destructive">Remove</Button>);
  const b = screen.getByRole('button', { name: 'Remove' });
  expect(b.className).toContain('bg-destructive');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- src/admin/__tests__/toolchain.test.tsx`
Expected: FAIL, module `@/admin/components/ui/button` not found.

- [ ] **Step 3: Replace Tailwind v3 with v4 and add the admin dependencies**

Run: `npm remove tailwindcss autoprefixer tailwindcss-v4 && npm i -D tailwindcss@^4.1 @tailwindcss/postcss@^4.1 @testing-library/react@^16 @testing-library/jest-dom@^6 @testing-library/user-event@^14 && npm i class-variance-authority clsx tailwind-merge lucide-react react-hook-form zod @hookform/resolvers sonner html2canvas-pro`.

Then in `src/tailwind/compiler.ts` and its test, change every import of `tailwindcss-v4` to `tailwindcss` (the webpack `asset/source` rule for that package path too).

- [ ] **Step 4: Configure PostCSS, shadcn and the admin stylesheet**

`postcss.config.js`: `{ plugins: { '@tailwindcss/postcss': {} } }`.

`components.json` (shadcn): `style: "new-york"`, `tsx: true`, `tailwind.css: "src/admin/styles/admin.css"`, `tailwind.cssVariables: true`, `aliases.components: "@/admin/components"`, `aliases.utils: "@/admin/lib/utils"`, `iconLibrary: "lucide"`.

`src/admin/styles/admin.css`:
- Import `tailwindcss/theme.css` and `tailwindcss/utilities.css` with the `important` import option; do **not** import `tailwindcss/preflight.css`.
- `@source "../**/*.{ts,tsx}"` so only `src/admin` is scanned.
- shadcn colour tokens declared on `#proto-blocks-admin` instead of `:root` (light only), mapped into `@theme inline`.
- A `@layer base` block that applies the preflight rules the components rely on (box-sizing, border-color, margin reset, font stack `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` at 14px) scoped under `#proto-blocks-admin, #proto-blocks-admin-portal`.

Run: `npx shadcn@latest add button` and confirm `src/admin/components/ui/button.tsx` and `src/admin/lib/utils.ts` exist.

- [ ] **Step 5: Point webpack, TypeScript and Jest at the new entry**

`webpack.config.js`: entry `admin: './src/admin/index.tsx'`; remove `DropEmptyScriptsPlugin`; keep `../css/[name].css` output. `tsconfig.json`: `"jsx": "react-jsx"`. `jest.config.js`: add `setupFilesAfterEnv: ['<rootDir>/jest.setup.ts']` and `transformIgnorePatterns: ['/node_modules/(?!(lucide-react|sonner)/)']`. `jest.setup.ts`: `import '@testing-library/jest-dom';`.

`src/admin/index.tsx` for now: `import './styles/admin.css';` and a `console.debug('proto-blocks admin')`.

- [ ] **Step 6: Run the tests and the build**

Run: `npm run test:js` → all pass including the new test and the renamed compiler test. Run: `npm run build` → `assets/js/admin.js`, `assets/js/admin.asset.php`, `assets/css/admin.css` exist; `grep -c "bg-destructive" assets/css/admin.css` is at least 1; `grep -c "html, :host" assets/css/admin.css` is 0 (no preflight). Run `composer test` → passes.

- [ ] **Step 7: Commit**

```bash
git add -A package.json package-lock.json postcss.config.js components.json jest.config.js jest.setup.ts tsconfig.json webpack.config.js src/admin src/tailwind
git rm tailwind.config.js
git commit -m "build(admin): move the admin to Tailwind 4, shadcn/ui and a React entry"
```

---

### Task 2: Mount point, bootstrap data and the React shell

**Files:**
- Create: `includes/Admin/AdminApp.php`, `tests/php/Admin/wp-stubs.php`, `tests/php/Admin/AdminAppTest.php`, `src/admin/bootstrap.ts`, `src/admin/api.ts`, `src/admin/app.tsx`, `src/admin/components/shell/{PageHeader,ReadOnlyBanner,PortalProvider,ScreenError,ConfirmDialog,StatCard}.tsx`, `src/admin/__tests__/helpers.tsx`, `src/admin/__tests__/api.test.ts`, `src/admin/__tests__/app.test.tsx`
- Modify: `includes/Admin/Assets.php:333-360` (`enqueueAdminAssets`), `includes/Core/Plugin.php` (admin wiring), `src/admin/index.tsx`

**Interfaces:**
- Produces (PHP): 
  ```php
  final class AdminApp {
      public const MOUNT_ID = 'proto-blocks-admin';
      public const PORTAL_ID = 'proto-blocks-admin-portal';
      public const SCREENS = ['dashboard', 'tailwind', 'system', 'previews', 'wizard'];
      public function __construct(?\ProtoBlocks\Tailwind\Manager $tailwind);
      public function bootstrapData(string $screen): array;   // throws InvalidArgumentException on unknown screen
      public function renderScreen(string $screen): void;    // echoes the two divs; adds inline script window.protoBlocksAdmin = {...}
  }
  ```
  Bootstrap keys: `screen`, `restRoot` (`rest_url('proto-blocks/v1/')`), `restNonce` (`wp_create_nonce('wp_rest')`), `adminUrl` (`admin_url('admin.php')`), `version`, `fileModsAllowed` (bool), `assetBase`, `tailwind` (`null` or `{ajaxUrl, actionPrefix: 'proto_blocks_tailwind_', nonce: wp_create_nonce('proto_blocks_tailwind_nonce')}`).
- Produces (TS):
  ```ts
  // bootstrap.ts
  export type Screen = 'dashboard'|'tailwind'|'system'|'previews'|'wizard';
  export interface AdminBootstrap { screen: Screen; restRoot: string; restNonce: string; adminUrl: string; version: string; fileModsAllowed: boolean; assetBase: string; tailwind: { ajaxUrl: string; actionPrefix: string; nonce: string } | null }
  export function getBootstrap(): AdminBootstrap;   // throws if window.protoBlocksAdmin is missing
  // api.ts
  export class ApiError extends Error { code: string; status: number }
  export function adminGet<T>(path: string): Promise<T>;
  export function adminPost<T>(path: string, body?: unknown): Promise<T>;
  export function adminDelete<T>(path: string): Promise<T>;
  // paths are relative to restRoot, e.g. 'admin/system'
  // app.tsx
  export function AdminApp({ bootstrap }: { bootstrap: AdminBootstrap }): JSX.Element;
  // shell
  export function PageHeader({ title, description, actions }: ...): JSX.Element;
  export function ReadOnlyBanner({ reason }: { reason: 'file_mods' | 'not_writable'; path?: string }): JSX.Element;
  export function ConfirmDialog({ open, title, description, confirmLabel, destructive, onConfirm, onCancel }: ...): JSX.Element;
  export function StatCard({ label, value, hint }: ...): JSX.Element;
  export function PortalProvider({ children }): JSX.Element;  // provides the #proto-blocks-admin-portal element to every Radix Portal via context
  // helpers.tsx
  export function mockBootstrap(overrides?: Partial<AdminBootstrap>): AdminBootstrap;  // sets window.protoBlocksAdmin
  export function mockApi(routes: Record<string, unknown | ((body: unknown) => unknown)>): jest.Mock; // replaces @wordpress/api-fetch
  ```

- [ ] **Step 1: Write the failing PHPUnit test**

`tests/php/Admin/AdminAppTest.php` (requires `wp-stubs.php`; set `$GLOBALS['pb_test_caps'] = [1 => ['manage_options']]`):
- `testBootstrapContainsScreenAndRestFields`: `bootstrapData('system')` has keys `screen`, `restRoot`, `restNonce`, `adminUrl`, `version`, `fileModsAllowed`, `assetBase`, `tailwind`; `screen === 'system'`; `restRoot` ends with `/proto-blocks/v1/`.
- `testUnknownScreenThrows`: `bootstrapData('nope')` throws `InvalidArgumentException`.
- `testTailwindNullWhenManagerAbsent`: constructed with `null` → `tailwind === null`.
- `testRenderScreenEchoesMountAndPortal`: output buffer of `renderScreen('dashboard')` contains `id="proto-blocks-admin"`, `data-screen="dashboard"`, `id="proto-blocks-admin-portal"` and `window.protoBlocksAdmin`.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/php/Admin/AdminAppTest.php`
Expected: FAIL, class `AdminApp` not found.

- [ ] **Step 3: Implement `AdminApp` and the stubs**

`tests/php/Admin/wp-stubs.php`: guard each with `function_exists`/`class_exists`: `current_user_can($cap, ...)` reading `$GLOBALS['pb_test_caps'][get_current_user_id()]`, `get_current_user_id()` returning `$GLOBALS['pb_test_user'] ?? 1`, `rest_url($path)` → `'https://example.test/wp-json/' . $path`, `wp_create_nonce($a)` → `'nonce-' . $a`, `admin_url($p)` → `'https://example.test/wp-admin/' . $p`, `esc_attr`, `esc_url_raw`, `wp_json_encode`, `wp_add_inline_script` (collects into `$GLOBALS['pb_test_inline']`), `register_rest_route` (collects into `$GLOBALS['pb_test_routes']`), minimal `WP_Error`, `WP_REST_Request` (`get_param`, `get_json_params`), `WP_REST_Response` (`get_data`, `get_status`).

`renderScreen` echoes the inline script itself in test context (print a `<script>` when `wp_add_inline_script` is the stub). Prefer `wp_add_inline_script('proto-blocks-admin', 'window.protoBlocksAdmin = ' . wp_json_encode($data) . ';', 'before')` in production and fall back to echo when the handle is not enqueued.

- [ ] **Step 4: Run the PHP test to verify it passes**

Run: `vendor/bin/phpunit tests/php/Admin/AdminAppTest.php` → PASS.

- [ ] **Step 5: Write the failing Jest tests**

`src/admin/__tests__/api.test.ts`:
- `adminGet` calls apiFetch with `{ path: 'admin/system', method: 'GET' }` after middleware set `restRoot` and `restNonce` (assert via the mocked `apiFetch.use` calls).
- A rejected apiFetch with `{ code: 'proto_blocks_file_mods', message: 'File modifications are disabled.', data: { status: 403 } }` becomes an `ApiError` with `code`, `message`, `status === 403`.

`src/admin/__tests__/app.test.tsx`:
- `AdminApp` with `screen: 'system'` renders the heading `System Status`.
- `getBootstrap()` throws `protoBlocksAdmin bootstrap missing` when the global is absent.
- `ReadOnlyBanner reason="file_mods"` renders text containing `File modifications are disabled on this site`; `reason="not_writable" path="/x"` renders `/x`.

- [ ] **Step 6: Run them to verify they fail**

Run: `npm run test:js -- src/admin/__tests__/api.test.ts src/admin/__tests__/app.test.tsx` → FAIL, modules not found.

- [ ] **Step 7: Implement the shell**

`api.ts` uses `@wordpress/api-fetch` with `createRootURLMiddleware(restRoot)` and `createNonceMiddleware(restNonce)` installed once from `getBootstrap()`. `src/admin/screens/index.ts` exports `SCREENS: Record<Screen, React.LazyExoticComponent>` so later tasks add one line each; `app.tsx` renders `SCREENS[bootstrap.screen]` in `<Suspense>` and renders a placeholder `<PageHeader title="System Status" />` for `system` (replaced in Task 3) and `ScreenError` for anything else for now; wraps in `PortalProvider` and shadcn `Toaster` (sonner). `index.tsx` mounts `<AdminApp bootstrap={getBootstrap()} />` with `createRoot` from `react-dom/client` into `#proto-blocks-admin` on `DOMContentLoaded`. `PortalProvider` creates a React context with the portal element; every shadcn component that renders a Radix `Portal` (`dialog`, `tooltip`, `select`, `popover`, `sonner`) is edited to pass `container={usePortalContainer()}`.

Run: `npx shadcn@latest add card badge switch radio-group input label table dialog alert tabs tooltip skeleton sonner checkbox separator`.

`Assets::enqueueAdminAssets` enqueues `proto-blocks-admin` from `assets/js/admin.js` with deps from `admin.asset.php` plus `wp-i18n`, and `assets/css/admin.css`; drops the two Google handles. `Plugin.php` constructs `AdminApp` and exposes `getAdminApp()`.

- [ ] **Step 8: Run all tests**

Run: `npm run test:js && composer test` → PASS.

- [ ] **Step 9: Commit**

```bash
git add includes/Admin/AdminApp.php includes/Admin/Assets.php includes/Core/Plugin.php tests/php/Admin src/admin
git commit -m "feat(admin): mount a React admin shell with bootstrap data"
```

---

### Task 3: REST controller and the System Status screen

**Files:**
- Create: `includes/API/AdminRestController.php`, `tests/php/API/AdminRestControllerTest.php`, `src/admin/screens/SystemScreen.tsx`, `src/admin/__tests__/system.test.tsx`
- Modify: `includes/Admin/AdminPage.php` (menu callback for `proto-blocks-system` → `AdminApp::renderScreen('system')`; delete `renderSystemStatusPage` lines 592–743), `includes/Core/Plugin.php` (construct controller, hook `rest_api_init`), `src/admin/app.tsx`

**Interfaces:**
- Produces (PHP):
  ```php
  final class AdminRestController {
      public const BASE = 'admin';
      public function __construct(AdminPage $adminPage, Cache $cache, Discovery $discovery, ?Manager $tailwind, ?AdminSettings $tailwindSettings, PreviewCapture $previewCapture, SetupWizard $wizard);
      public function registerRoutes(): void;
      public function permission(): bool;        // current_user_can('manage_options')
      public function writePermission(): bool;   // permission() && AdminPage::fileModsAllowed()
      public static function relativePath(string $absolute, string $root): string;  // strips $root prefix, else returns $absolute
      public static function fileModsError(): \WP_Error;   // 'proto_blocks_file_mods', status 403
  }
  ```
  **Structure for parallel additions (controller ruling):** each screen's
  routes live in their own class `includes/API/Admin/<Name>Routes.php` with
  `registerRoutes(string $namespace, callable $permission, callable $writePermission): void`,
  and `AdminRestController::ROUTE_GROUPS` lists those classes; this task
  creates `SystemRoutes` and the list, later tasks add one class and one line.
  Routes this task: `GET admin/system` → `{ categoryName, config: {debug, cacheEnabled, exampleBlocks, exampleBlocksFromConfig}, system: {pluginVersion, wpVersion, phpVersion, cacheDir, cacheDirWritable} }`; `POST admin/system` body `{categoryName}` → same payload after `update_option(AdminPage::OPTION_CATEGORY_NAME, sanitize_text_field(...))`.
- Produces (TS): `SystemScreen(): JSX.Element` fetching `admin/system`, editing the category name with a react-hook-form + zod form (`z.string().max(100)`), showing the two read-only tables, and toasting `Category name saved.`

- [ ] **Step 1: Write the failing PHPUnit tests**

`tests/php/API/AdminRestControllerTest.php` (uses `tests/php/Admin/wp-stubs.php`):
- `testRelativePathStripsRoot`: `relativePath('/srv/wp/wp-content/themes/t', '/srv/wp/')` → `wp-content/themes/t`; outside root returns input unchanged.
- `testWritePermissionFalseWhenFileModsDisabled`: with `$GLOBALS['pb_test_caps'] = [1 => ['manage_options']]` and `define('DISALLOW_FILE_MODS', true)` (test runs in a separate process: `@runInSeparateProcess`), `writePermission()` is false while `permission()` is true.
- `testFileModsErrorShape`: `fileModsError()->get_error_code() === 'proto_blocks_file_mods'`, status 403.
- `testRegisterRoutesDeclaresSystemRoutes`: after `registerRoutes()`, `$GLOBALS['pb_test_routes']` contains `admin/system` with methods `GET` and `POST` under namespace `proto-blocks/v1`.

Construct the controller with the real `AdminPage`/`Cache` only if they instantiate without WordPress; otherwise pass `null`-able test doubles by making the constructor parameters interfaces-free and accepting `object` in tests via anonymous subclasses. The `relativePath`, `fileModsError` and permission tests must not need the dependencies.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/API/AdminRestControllerTest.php` → FAIL, class not found.

- [ ] **Step 3: Implement the controller with the system routes**

Register routes with `register_rest_route(RestAPI::NAMESPACE, '/' . self::BASE . '/system', [...])`, `permission_callback => [$this, 'permission']`; `POST` arg `categoryName` `type: string`, `sanitize_callback: sanitize_text_field`. `cacheDir` returned through `relativePath(Cache::getCacheDir(), ABSPATH)`.

- [ ] **Step 4: Run PHP tests** → PASS.

- [ ] **Step 5: Write the failing Jest test**

`src/admin/__tests__/system.test.tsx` with `mockApi({ 'admin/system': {...payload with categoryName: 'Proto Blocks', config.debug: false, system.cacheDirWritable: false} })`:
- renders `Plugin Version`, `PHP Version`, and `Not writable` for the cache dir.
- typing `Shop blocks` and submitting calls `adminPost('admin/system', { categoryName: 'Shop blocks' })` and shows the toast `Category name saved.`
- when `config.exampleBlocksFromConfig` is false, a link `Managed from the Blocks page` points to `adminUrl?page=proto-blocks`.

- [ ] **Step 6: Run to verify failure** → FAIL.

- [ ] **Step 7: Implement `SystemScreen` and route it in `app.tsx`**

Use shadcn `Card`, `Table`, `Input`, `Button`, `Badge`; `useForm` with `zodResolver`; `toast` from sonner. Replace the Task 2 placeholder.

- [ ] **Step 8: Wire PHP, delete the old page, run everything**

`AdminPage::addMenuPage` points `proto-blocks-system` at `fn() => Plugin::getInstance()->getAdminApp()->renderScreen('system')`; delete `renderSystemStatusPage` and its POST handling. `Plugin.php`: construct `AdminRestController` and `add_action('rest_api_init', [$controller, 'registerRoutes'])`.

Run: `composer test && npm run test:js && npm run build` → PASS. Manual check on a Local site: `admin.php?page=proto-blocks-system` renders the React screen and saving the category name persists.

- [ ] **Step 9: Commit**

```bash
git add includes/API/AdminRestController.php includes/Admin/AdminPage.php includes/Core/Plugin.php tests/php src/admin
git commit -m "feat(admin): REST admin controller and React System Status screen"
```

---

### Task 4: Dashboard screen

**Files:**
- Modify: `includes/Admin/AdminPage.php` (delete `renderPage` 90–545 and `printSkippedDemoBlocksNotice`; make `removeDemoBlocks(): int|WP_Error` public; add `public function dashboardData(): array` and `public function installPath(): string`), `includes/API/AdminRestController.php`, `tests/php/API/AdminRestControllerTest.php`, `tests/php/Admin/AdminPageDemoCopyTest.php` (unchanged behaviour, must still pass), `src/admin/app.tsx`
- Create: `src/admin/screens/DashboardScreen.tsx`, `src/admin/__tests__/dashboard.test.tsx`

**Interfaces:**
- Produces (PHP):
  - `AdminPage::dashboardData(): array` → `{ stats: {blocks, cachedTemplates, cacheSize}, exampleBlocks: {enabled, locked}, demos: {vanilla: string[], tailwind: string[], tailwindEnabled, installPath}, installed: string[], conflicts: string[], skipped: string[], blocks: [{name, title, description, category, location, usesTailwind, hasJson, hasCSS, hasJS, isExample}] }` where `location` is `relativePath(path, ABSPATH)`.
  - `AdminRestController::exampleBlocksState(bool $fromConfig, bool $constantValue, mixed $optionValue): array{enabled: bool, locked: bool}`.
  - `AdminRestController::validateDemoType(string $type, bool $tailwindEnabled): ?\WP_Error` → `proto_blocks_invalid_type` (400) for anything but `vanilla`/`tailwind`; `proto_blocks_tailwind_disabled` (409) for `tailwind` when disabled; `null` otherwise.
  - Routes: `GET admin/dashboard`; `POST admin/example-blocks {enabled: bool}` → 409 `proto_blocks_locked` when locked, else updates the option, clears the cache, returns `dashboardData()`; `POST admin/demos/install {type}` (writePermission) → `{installed: int, skipped: string[]}` plus the data, WP_Error from `installDemoBlocks` passed through with status 400; `POST admin/demos/remove` (writePermission) → `{removed: int}` plus data. Cache clearing reuses the existing `DELETE cache`.
- Produces (TS): `DashboardScreen()` with the three stat cards, example-blocks switch, demo install cards, remove section with `ConfirmDialog` listing installed names, and the blocks table.

- [ ] **Step 1: Write the failing PHPUnit tests** (add to `AdminRestControllerTest`)

- `testExampleBlocksStateLockedByConfig`: `exampleBlocksState(true, true, false)` → `['enabled' => true, 'locked' => true]`.
- `testExampleBlocksStateFromOption`: `exampleBlocksState(false, false, '1')` → enabled true, locked false.
- `testValidateDemoTypeRejectsUnknown`: `validateDemoType('all', true)` is a `WP_Error` with code `proto_blocks_invalid_type`.
- `testValidateDemoTypeRequiresTailwind`: `validateDemoType('tailwind', false)` → `proto_blocks_tailwind_disabled`; `validateDemoType('tailwind', true)` → null.

- [ ] **Step 2: Run to verify failure** → FAIL, undefined methods.

- [ ] **Step 3: Implement the PHP side**

Move the data-gathering parts of the old `renderPage` into `dashboardData()`; the handlers become the routes above. The `locked` POST returns `new WP_Error('proto_blocks_locked', __('Example blocks are managed from the configuration constant.', 'proto-blocks'), ['status' => 409])`.

- [ ] **Step 4: Run PHP tests** → PASS, including `AdminPageDemoCopyTest`.

- [ ] **Step 5: Write the failing Jest tests**

`src/admin/__tests__/dashboard.test.tsx`:
- renders stat values `12`, `4`, `1 MB` from `stats`.
- `exampleBlocks.locked: true` → the switch is disabled and `Managed from configuration` is shown; `locked: false` → toggling calls `adminPost('admin/example-blocks', { enabled: false })`.
- `demos.tailwindEnabled: false` → the Tailwind install button is disabled and `Requires Tailwind` badge shown with a link to `?page=proto-blocks-settings`.
- `fileModsAllowed: false` in bootstrap → `ReadOnlyBanner` is shown and both install buttons and the remove button are disabled.
- clicking `Remove demo blocks` opens the confirm dialog listing `hero-banner`; confirming calls `adminPost('admin/demos/remove')` and toasts `1 demo block removed.`
- an empty `blocks` array shows `No blocks found`.

- [ ] **Step 6: Run to verify failure** → FAIL.

- [ ] **Step 7: Implement `DashboardScreen`** and route `dashboard` in `app.tsx`; point the `proto-blocks` menu callback at `renderScreen('dashboard')`.

- [ ] **Step 8: Run everything** `composer test && npm run test:js && npm run build` → PASS. Manual: install and remove vanilla demos on a Local site.

- [ ] **Step 9: Commit**

```bash
git add includes src/admin tests
git commit -m "feat(admin): React Blocks dashboard over REST"
```

---

### Task 5: Tailwind Settings screen

**Files:**
- Modify: `includes/Tailwind/AdminSettings.php` (delete `render()` and all handlers except `handleGetCompileInputs`, `handleStoreCss`, `verifyNonce`; add `KEPT_AJAX_ACTIONS`; register only those), `includes/API/AdminRestController.php`, `tests/php/API/AdminRestControllerTest.php`, `includes/Admin/AdminPage.php` (`renderSettingsPage` → `renderScreen('tailwind')`), `src/admin/app.tsx`
- Create: `src/admin/screens/TailwindScreen.tsx`, `src/admin/__tests__/tailwind.test.tsx`, `tests/php/Tailwind/AdminSettingsActionsTest.php`

**Interfaces:**
- Produces (PHP):
  - `AdminRestController::normalizeTailwindSettings(array $input): array|\WP_Error` → accepts any subset of `enabled` (bool), `mode` (`cached|on_reload`), `engine` (`auto|cli|browser`), `disableGlobalStyles` (bool); returns the normalised subset or `WP_Error('proto_blocks_invalid_setting', ..., 400)`.
  - Routes: `GET admin/tailwind` → `{ status: Manager::getStatus(), settings: {enabled, mode, engine, disableGlobalStyles, lastCompiled}, config: {path (relative), exists, preview}, cssSizeFormatted }`; `POST admin/tailwind/settings` body as above → applies via `enable()/disable()`, `setMode`, `setEngine`, `setDisableGlobalStyles`, returns the GET payload; `POST admin/tailwind/compile` → `Manager::compile()`, 500 `proto_blocks_compile_failed` with its message on failure, else the GET payload; `DELETE admin/tailwind/cache` → `clearCache(false)`; `POST admin/tailwind/cli` (writePermission) → `BinaryManager::download(true)`, errors as 500 `proto_blocks_cli_download`; `POST admin/tailwind/theme-file` (writePermission) → `ConfigEditor::createThemeFile()` semantics of the old handler, 409 `proto_blocks_exists` when the file exists.
  - `AdminSettings::KEPT_AJAX_ACTIONS = ['get_compile_inputs', 'store_css']`.
- Produces (TS): `TailwindScreen()`; when `status.engine === 'browser'` the Compile button calls `window.ProtoBlocksTailwind.runBrowserCompile({ ajaxUrl, actionPrefix, nonce })` from `bootstrap.tailwind`, otherwise `adminPost('admin/tailwind/compile')`; after any settings change, CLI download or compile the screen refetches `admin/tailwind`.
  CLI status text by state, in this order: engine browser → `Browser engine (CLI not used)`; `cli_functional` → `Installed · v{cli_version}`; installed and `cli_trust_state === 'mismatch'` → `Checksum mismatch — re-download`; installed and `'unverified'` → `No recorded checksum — re-download`; installed, not functional → `Installed but not runnable`; else `Not installed`.

- [ ] **Step 1: Write the failing PHPUnit tests**

In `AdminRestControllerTest`:
- `testNormalizeTailwindSettingsAcceptsSubset`: `['mode' => 'on_reload', 'enabled' => '1']` → `['mode' => 'on_reload', 'enabled' => true]`.
- `testNormalizeTailwindSettingsRejectsBadEngine`: `['engine' => 'wasm']` → `WP_Error` code `proto_blocks_invalid_setting`.
- `testNormalizeTailwindSettingsIgnoresUnknownKeys`: `['foo' => 1]` → `[]`.

New `tests/php/Tailwind/AdminSettingsActionsTest.php`: `AdminSettings::KEPT_AJAX_ACTIONS === ['get_compile_inputs', 'store_css']` and `method_exists(AdminSettings::class, 'handleToggle')` is false.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the PHP side**, deleting the old handlers and `render()`.

- [ ] **Step 4: Run PHP tests** → PASS (the existing `tests/php/Tailwind/*` must still pass).

- [ ] **Step 5: Write the failing Jest tests**

`src/admin/__tests__/tailwind.test.tsx`:
- a table-driven test over the six CLI states asserting the exact text above.
- changing the engine radio to `cli` calls `adminPost('admin/tailwind/settings', { engine: 'cli' })` and refetches `admin/tailwind` (mock called twice).
- with `status.engine: 'browser'`, clicking Compile calls `window.ProtoBlocksTailwind.runBrowserCompile` with `{ ajaxUrl, actionPrefix: 'proto_blocks_tailwind_', nonce }` from the bootstrap and, on `{ success: true, message: 'ok', cssSizeFormatted: '12 KB' }`, toasts `ok` and refetches.
- `status.shell_available: false` hides the CLI download button and shows the shell warning.
- `fileModsAllowed: false` disables Download CLI and Create theme file.
- Clear cache opens `ConfirmDialog`; confirming calls `adminDelete('admin/tailwind/cache')`.

- [ ] **Step 6: Run to verify failure** → FAIL.

- [ ] **Step 7: Implement `TailwindScreen`** (shadcn `Switch`, `RadioGroup`, `Card`, `Alert`, `Badge`, `Tabs` for the theme-config preview in a `<pre>`), route it, point `proto-blocks-settings` at `renderScreen('tailwind')`.

- [ ] **Step 8: Run everything** → PASS. Manual on a Local site: toggle Tailwind, switch engine, browser compile, CLI compile where the shell is available, auto-compile still runs on the front end in `on_reload` mode.

- [ ] **Step 9: Commit**

```bash
git add includes src/admin tests
git commit -m "feat(admin): React Tailwind settings screen; drop per-control AJAX handlers"
```

---

### Task 6: Preview Capture screen

**Files:**
- Modify: `includes/Admin/PreviewCapture.php` (`route()` → `renderScreen('previews')`; delete `renderAdminUi` and `enqueueAssets`; make `collectBlocks()` public), `includes/API/AdminRestController.php`, `includes/Core/Plugin.php` (remove the `enqueueAssets` hook), `src/admin/app.tsx`
- Create: `src/admin/lib/capture.ts`, `src/admin/screens/PreviewsScreen.tsx`, `src/admin/__tests__/capture.test.ts`, `src/admin/__tests__/previews.test.tsx`
- Delete: `assets/admin/preview-capture.js`, `assets/vendor/html2canvas-pro.min.js`, `assets/css/preview-capture.css`

**Interfaces:**
- Produces (PHP): `GET admin/previews` → `{ blocks: PreviewCapture::collectBlocks() items {name, title, hasPreview, previewUrl, previewMtime, renderUrl}, width: 1280, settleMs: apply_filters('proto_blocks_capture_settle_ms', 1000) }` (no `path`). Uploads keep using the existing `POST preview-capture`.
- Produces (TS):
  ```ts
  // lib/capture.ts
  export interface CaptureRow { name: string; renderUrl: string }
  export type RowStatus = 'rendering'|'rasterizing'|'uploading'|'captured'|'failed';
  export interface CaptureDeps { iframe: HTMLIFrameElement; width: number; settleMs: number; rasterize: (body: HTMLElement, opts: {width:number; height:number}) => Promise<HTMLCanvasElement>; upload: (block: string, image: string) => Promise<{ previewUrl: string; mtime: number }>; loadTimeoutMs?: number /* 15000 */ }
  export async function captureOne(row: CaptureRow, deps: CaptureDeps, onStatus: (s: RowStatus, message?: string) => void): Promise<{ previewUrl: string; mtime: number }>;
  export async function captureMany(rows: CaptureRow[], deps: CaptureDeps, onRow: (name: string, s: RowStatus, message?: string) => void, onProgress: (done: number, total: number) => void): Promise<{ captured: number; failed: number }>;
  ```
  Row error messages, verbatim: `Missing render URL.`, `Iframe failed to load.`, `Rasterize failed.`, `Upload failed.`, and the REST message for a rejection. `rasterize` is supplied by the screen through `await import('html2canvas-pro')` on first use.

- [ ] **Step 1: Write the failing Jest test for the sequencer**

`src/admin/__tests__/capture.test.ts` with a fake iframe (a plain object with `src` setter that fires `onload` on a microtask, `contentDocument` with `body`, `fonts.ready` resolved, no images):
- `captureMany` runs rows sequentially (second `rasterize` call starts after the first `upload` resolved).
- a row whose `upload` rejects with `new Error('Upload rejected: too large')` reports `failed` with that message and the batch continues; the result is `{ captured: 1, failed: 1 }` for two rows.
- a row with empty `renderUrl` fails immediately with `Missing render URL.` without touching the iframe.
- a row whose iframe never loads fails with `Iframe failed to load.` when `loadTimeoutMs` is 10 (use fake timers).
- `onStatus` is called in order `rendering`, `rasterizing`, `uploading`, `captured`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement `lib/capture.ts`** porting `assets/admin/preview-capture.js` (same readiness waits: `fonts.ready` 5 s, each image 5 s, then `settleMs`; iframe height from `body.scrollHeight`).

- [ ] **Step 4: Run the test** → PASS.

- [ ] **Step 5: Write the failing screen test**

`src/admin/__tests__/previews.test.tsx` with `mockApi({ 'admin/previews': { blocks: [...two blocks, one hasPreview false], width: 1280, settleMs: 0 } })` and `jest.mock('html2canvas-pro')`:
- renders both rows with `Present` / `Missing` badges.
- `Capture missing` captures only the missing row (one `fetch` to `preview-capture` with header `X-WP-Nonce`), then its badge becomes `Present` and the global status reads `Done. 1 captured, 0 failed.`
- `fileModsAllowed: false` disables all capture buttons and shows the banner.
- the select-all checkbox checks every row and `Capture selected` captures them.

- [ ] **Step 6: Run to verify failure** → FAIL.

- [ ] **Step 7: Implement `PreviewsScreen`** (table, checkboxes, hidden iframe with `sandbox="allow-same-origin allow-scripts"` and `referrerpolicy="no-referrer"`, aria-live status), the PHP route, delete the old assets and `enqueueAssets`.

- [ ] **Step 8: Run everything** → PASS; `npm run build` emits the lazy `html2canvas-pro` chunk under `assets/js/` and the screen loads it (check `window.protoBlocksAssetBase` is still set before the admin script so `__webpack_public_path__` resolves). Manual: capture one block on a Local site.

- [ ] **Step 9: Commit**

```bash
git add -A includes src/admin tests assets
git commit -m "feat(admin): React preview capture screen; bundle html2canvas-pro"
```

---

### Task 7: Setup Wizard screen

**Files:**
- Modify: `includes/Admin/SetupWizard.php` (delete `renderWizard` and `handleFormSubmission`; add the methods below; the submenu callback → `renderScreen('wizard')`), `includes/API/AdminRestController.php`, `tests/php/Admin/SetupWizardComponentStyleTest.php` (keep), `src/admin/app.tsx`
- Create: `tests/php/Admin/SetupWizardFlowTest.php`, `src/admin/screens/WizardScreen.tsx`, `src/admin/__tests__/wizard.test.tsx`

**Interfaces:**
- Produces (PHP):
  ```php
  public function data(): array;   // { style, categoryName, demos: {vanilla: string[], tailwind: string[]}, installPath, completed: bool }
  public function saveStyle(string $style, string $categoryName): void;   // normalises style, updates both options, enables Tailwind when 'tailwind'
  public function installDemos(): array|\WP_Error;  // { installed: int, skipped: string[] } using the stored style; WP_Error passthrough
  public function complete(): void;  // update_option(OPTION_COMPLETED, true)
  ```
  Routes: `GET admin/wizard`; `POST admin/wizard/style {style, categoryName}`; `POST admin/wizard/demos` (writePermission); `POST admin/wizard/complete` → `{ redirect: admin_url('admin.php?page=proto-blocks') }`.
- Produces (TS): `WizardScreen()` with four steps held in component state (Welcome, Style, Import, Finish), a step indicator, and the Finish summary (`Styling Approach`, `Category Name` defaulting to `Proto Blocks`, `Example Blocks Installed` as a count or `Skipped`, `Tailwind Integration` when style is tailwind).

- [ ] **Step 1: Write the failing PHPUnit test**

`tests/php/Admin/SetupWizardFlowTest.php` (options via `$GLOBALS['pb_test_options']`; stub `update_option` into it if the bootstrap lacks one):
- `saveStyle('Tailwind ', 'Shop')` stores `proto_blocks_component_style = 'tailwind'` and `proto_blocks_category_name = 'Shop'`.
- `saveStyle('bogus', '')` stores `vanilla`.
- `complete()` stores `proto_blocks_wizard_completed = true`.
- `data()['completed']` is false before and true after.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the PHP methods and routes**, deleting the form handling. Keep `maybeRedirectToWizard` and `hideWizardMenuItem` unchanged.

- [ ] **Step 4: Run PHP tests** → PASS.

- [ ] **Step 5: Write the failing Jest test**

`src/admin/__tests__/wizard.test.tsx`:
- starts on `Welcome`; `Get Started` moves to `Choose your style`.
- selecting `Tailwind`, typing `Shop` and `Continue` calls `adminPost('admin/wizard/style', { style: 'tailwind', categoryName: 'Shop' })` and shows step 3 listing the tailwind demo names.
- `Skip for now` reaches Finish with `Skipped`; `Install Example Blocks` calls `adminPost('admin/wizard/demos')` and shows `2` installed and the skipped names in an amber alert.
- `fileModsAllowed: false` shows the banner and disables Install.
- `Go to Dashboard` calls `adminPost('admin/wizard/complete')` and assigns `window.location.href` to the returned `redirect`.

- [ ] **Step 6: Run to verify failure** → FAIL.

- [ ] **Step 7: Implement `WizardScreen`** and route it.

- [ ] **Step 8: Run everything** → PASS. Manual: delete `proto_blocks_wizard_completed` on a Local site and run through the wizard.

- [ ] **Step 9: Commit**

```bash
git add includes src/admin tests
git commit -m "feat(admin): React setup wizard over REST"
```

---

### Task 8: Cleanup, docs and header alignment

**Files:**
- Modify: `includes/Admin/AdminPage.php` (remove any leftover HTML helpers), `includes/Admin/Assets.php` (remove unused handles and the `#proto-blocks-admin-app` comment), `proto-blocks.php` (`Requires PHP: 8.1`), `README.md` (admin section), `CHANGELOG.md` (Unreleased: admin rebuild, Tailwind v3 removed, AJAX actions removed except the two compiler ones, PHP 8.1), `webpack.config.js` (drop any dead rule for the old admin CSS)
- Delete: anything in `assets/` no longer referenced (`grep -rn "assets/css/preview-capture\|preview-capture.js\|html2canvas-pro.min" includes src` must return nothing)

- [ ] **Step 1: Write the guard test**

Add `tests/php/Admin/DeadCodeTest.php`: `method_exists(AdminPage::class, 'renderPage')`, `'renderSettingsPage'`, `'renderSystemStatusPage'`, `method_exists(SetupWizard::class, 'renderWizard')`, `method_exists(PreviewCapture::class, 'renderAdminUi')`, `method_exists(AdminSettings::class, 'render')` are all false; the plugin header file contains `Requires PHP: 8.1`.

- [ ] **Step 2: Run to verify failure** → FAIL on the header assertion at least.

- [ ] **Step 3: Make the changes**, then `npm run build && npm run zip` and confirm the zip contains `assets/js/admin.js`, `assets/css/admin.css` and no `assets/vendor/`.

- [ ] **Step 4: Run everything** → `composer test && npm run test:js` PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(admin): remove the PHP-rendered admin, require PHP 8.1"
```

---

### Task 9: Tests in CI

**Files:**
- Create: `.github/workflows/test.yml`
- Modify: `.github/workflows/release.yml`

**Interfaces:**
- `test.yml`: on `pull_request` and `push` to `main`; jobs `php` (`shivammathur/setup-php` 8.1 and 8.3 matrix, `composer install --no-interaction`, `composer test`) and `js` (`actions/setup-node` 20, `npm ci`, `npm run test:js`, `npm run test:scripts`, `npm run build`). Every action pinned to a commit SHA with a `# vX.Y.Z` comment, `permissions: contents: read`.
- `release.yml`: a `test` job with the same steps; the existing release job gains `needs: test`.

- [ ] **Step 1: Resolve the SHAs**

Run for each action (`actions/checkout`, `actions/setup-node`, `shivammathur/setup-php`): `gh api repos/<owner>/<repo>/git/ref/tags/<latest tag> --jq .object.sha` (dereference annotated tags with `git/tags/<sha>` if `type` is `tag`).

- [ ] **Step 2: Write the workflows** as specified.

- [ ] **Step 3: Verify**

Run: `gh workflow run test.yml --ref main` after push, or open a PR from a throwaway branch; `gh run watch` shows both jobs green. `actionlint .github/workflows/*.yml` (install via `brew install actionlint`) reports nothing.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows
git commit -m "ci: run PHPUnit and Jest on pushes and gate releases on them"
```

---

## Self-review notes

- Spec coverage for Phase 0: React shell (T2), every current screen rebuilt (T3–T7), REST replacing form POSTs and AJAX "where practical" with the two compiler actions kept (T5), Tailwind v3 removed and v4 scoped without preflight (T1), read-only mode (T2–T7), `manage_options` plus `fileModsAllowed` (T3), PHPUnit and Jest in CI (T9), PHP 8.1 header (T8). Screen layouts are left to the Design artifact pass as the spec says; the tasks fix content and behaviour, not pixels.
- Names used across tasks: `AdminApp::renderScreen`, `AdminRestController::{permission,writePermission,relativePath,fileModsError,exampleBlocksState,validateDemoType,normalizeTailwindSettings}`, `adminGet/adminPost/adminDelete`, `ReadOnlyBanner`, `ConfirmDialog`, `mockBootstrap`, `mockApi`, `captureOne/captureMany` — each defined once and referenced by the same name afterwards.
