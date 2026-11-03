import { Fragment, type ReactNode } from 'react';

/**
 * Puts React nodes into a translated string at its %1$s, %2$s… (or %s)
 * placeholders, so translators keep control of word order:
 *   interpolate(__('%s is set in wp-config.php.'), <code>X</code>)
 */
export function interpolate(text: string, ...nodes: ReactNode[]): ReactNode {
	let next = 0;
	const parts = text.split(/(%(?:\d+\$)?s)/);
	return parts.map((part, i) => {
		const match = /^%(?:(\d+)\$)?s$/.exec(part);
		if (!match) {
			return part === '' ? null : <Fragment key={i}>{part}</Fragment>;
		}
		const index = match[1] ? Number(match[1]) - 1 : next++;
		return <Fragment key={i}>{nodes[index]}</Fragment>;
	});
}
