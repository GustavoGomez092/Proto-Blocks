/**
 * The browser Tailwind engine imports the bundled `tailwindcss/*.css` files
 * as raw source strings (webpack `type: 'asset/source'`). Declare them as
 * default string exports so TypeScript accepts the imports.
 */
declare module 'tailwindcss/index.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss/theme.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss/preflight.css' {
	const content: string;
	export default content;
}
declare module 'tailwindcss/utilities.css' {
	const content: string;
	export default content;
}
