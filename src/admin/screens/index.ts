import { lazy, type LazyExoticComponent } from 'react';
import { __ } from '@wordpress/i18n';
import type { Screen } from '@/admin/bootstrap';

type ScreenComponent = LazyExoticComponent<() => JSX.Element>;

/**
 * One lazily loaded component per screen. Add a line here when a screen is
 * built; a screen without an entry renders ScreenError. Partial until every
 * screen exists.
 */
export const SCREENS: Partial<Record<Screen, ScreenComponent>> = {
	system: lazy(() => import('./SystemScreen')),
	wizard: lazy(() => import('./WizardScreen')),
};

/** Screen name as shown in the WordPress menu, for headings and errors. */
export function screenLabel(screen: string): string {
	switch (screen) {
		case 'dashboard':
			return __('Blocks', 'proto-blocks');
		case 'tailwind':
			return __('Tailwind Settings', 'proto-blocks');
		case 'system':
			return __('System Status', 'proto-blocks');
		case 'previews':
			return __('Preview Capture', 'proto-blocks');
		case 'wizard':
			return __('Setup Wizard', 'proto-blocks');
		default:
			return screen;
	}
}
