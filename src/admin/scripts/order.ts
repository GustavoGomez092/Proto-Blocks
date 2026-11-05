/**
 * Print order helpers for the Scripts screens: at each location scripts
 * print by priority, then by slug (ScriptsModule's sort).
 */
import { LOCATIONS, type Location, type ScriptRow } from './types';

export interface OrderEntry {
	slug: string;
	title: string;
	enabled: boolean;
	priority: number;
}

function compare(a: OrderEntry, b: OrderEntry): number {
	return a.priority - b.priority || (a.slug < b.slug ? -1 : a.slug > b.slug ? 1 : 0);
}

/** Every script that prints something at each location, in print order (disabled ones included, flagged). */
export function printOrder(items: ScriptRow[]): Record<Location, OrderEntry[]> {
	const order = { head: [], bodyOpen: [], bodyClose: [] } as Record<Location, OrderEntry[]>;
	for (const location of LOCATIONS) {
		order[location] = items
			.filter((item) => item.locations.includes(location))
			.map((item) => ({ slug: item.slug, title: item.title, enabled: item.enabled, priority: item.priorities?.[location] ?? item.priority }))
			.sort(compare);
	}
	return order;
}

/**
 * The first enabled script that prints after this one at a location both use,
 * or null. `self` is the script being edited (its unsaved priority and locations).
 */
export function printsBefore(
	self: { slug: string | null; priority: number; locations: Location[] },
	items: ScriptRow[]
): OrderEntry | null {
	const me: OrderEntry = { slug: self.slug ?? '￿', title: '', enabled: true, priority: self.priority };
	let next: OrderEntry | null = null;
	for (const location of self.locations) {
		for (const item of items) {
			if (item.slug === self.slug || !item.enabled || !item.locations.includes(location)) {
				continue;
			}
			const entry: OrderEntry = { slug: item.slug, title: item.title, enabled: true, priority: item.priorities?.[location] ?? item.priority };
			if (compare(me, entry) < 0 && (next === null || compare(entry, next) < 0)) {
				next = entry;
			}
		}
	}
	return next;
}
