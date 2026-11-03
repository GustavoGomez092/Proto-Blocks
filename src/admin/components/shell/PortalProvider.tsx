import { createContext, useContext, useState, type ReactNode } from 'react';

/** Id of the node AdminApp.php prints next to the mount node. */
export const PORTAL_ID = 'proto-blocks-admin-portal';

const PortalContext = createContext<HTMLElement | null>(null);

/**
 * Gives every Radix Portal (dialogs, tooltips) and the toaster the
 * #proto-blocks-admin-portal element, where the scoped tokens and base
 * styles apply. Outside a provider, or without the node, Radix falls back to
 * document.body.
 */
export function PortalProvider({ container, children }: { container?: HTMLElement | null; children: ReactNode }) {
	const [element] = useState<HTMLElement | null>(
		() => container ?? (typeof document === 'undefined' ? null : document.getElementById(PORTAL_ID))
	);
	return <PortalContext.Provider value={element}>{children}</PortalContext.Provider>;
}

/** The portal container for Radix `Portal` parts; undefined means document.body. */
export function usePortalContainer(): HTMLElement | undefined {
	return useContext(PortalContext) ?? undefined;
}
