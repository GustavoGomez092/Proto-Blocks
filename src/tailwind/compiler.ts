/**
 * Browser-side Tailwind compile orchestration for Proto-Blocks.
 *
 * Fetches the compile inputs from the server, compiles Tailwind in the
 * browser, and posts the resulting CSS back to be scoped + saved. Used on
 * hosts without shell access (e.g. WP Engine).
 */

// Point webpack's runtime asset loader (used for the Lightning CSS .wasm that
// the engine loads) at the plugin's real assets URL. WordPress enqueues this
// bundle from wp-content/plugins/.../assets/js/, but webpack's 'auto'
// publicPath can mis-resolve the .wasm against the page URL and 404 (the
// server returns an HTML error page, so WebAssembly.instantiate sees `<!DO`
// instead of the wasm magic bytes). The PHP side prints `protoBlocksAssetBase`
// just before this script; honor it. Must run before any wasm URL is built.
declare let __webpack_public_path__: string;
if (typeof window !== 'undefined') {
    const base = (window as unknown as { protoBlocksAssetBase?: string })
        .protoBlocksAssetBase;
    if (base) {
        __webpack_public_path__ = base;
    }
}

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

    // Keep the contract honest: a thrown engine error becomes a failure result
    // rather than a rejected promise, so callers can rely on { success, message }.
    let css: string;
    try {
        css = await compileTailwind(inputCss, content);
    } catch (err) {
        return {
            success: false,
            message: err instanceof Error ? err.message : 'Tailwind compilation failed.',
        };
    }

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
        ProtoBlocksTailwind?: {
            runBrowserCompile: (
                d: Omit<RunBrowserCompileDeps, 'post'>
            ) => Promise<RunBrowserCompileResult>;
        };
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
