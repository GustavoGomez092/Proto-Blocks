import '@testing-library/jest-dom';

// jsdom lacks the pointer-capture and scroll APIs Radix Select calls.
if (typeof Element !== 'undefined') {
	const proto = Element.prototype as unknown as Record<string, unknown>;
	proto.hasPointerCapture ??= () => false;
	proto.setPointerCapture ??= () => undefined;
	proto.releasePointerCapture ??= () => undefined;
	proto.scrollIntoView ??= () => undefined;
}
