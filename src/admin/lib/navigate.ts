/** Leaves the screen for `url`. A function so tests can replace it (jsdom cannot navigate). */
export function navigate(url: string): void {
	window.location.href = url;
}
