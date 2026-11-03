/**
 * Screens are lazy chunks next to admin.js. Resolve them from the bootstrap
 * assetBase (same base the Tailwind compiler uses) instead of webpack's
 * `auto` guess from document.currentScript, which breaks when a host or
 * optimisation plugin concatenates or re-injects scripts. Imported first by
 * index.tsx, before any chunk can load.
 */
declare let __webpack_public_path__: string;

const base = typeof window === 'undefined' ? undefined : window.protoBlocksAdmin?.assetBase;
if (base) {
	__webpack_public_path__ = base;
}

export {};
