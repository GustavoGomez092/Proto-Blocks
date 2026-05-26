/**
 * The browser Tailwind engine imports the bundled `tailwindcss-v4/*.css` files
 * as raw source strings (webpack `type: 'asset/source'`). Declare them as
 * default string exports so TypeScript accepts the imports.
 */
declare module 'tailwindcss-v4/index.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss-v4/theme.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss-v4/preflight.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss-v4/utilities.css' {
	const content: string;
	export default content;
}
