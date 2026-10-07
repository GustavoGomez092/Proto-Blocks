/**
 * Which repeater item the sidebar is talking about.
 *
 * A repeater item is not a block, so Gutenberg has no per-item inspector to
 * hook: the sidebar belongs to whichever block is selected. What it *shows*,
 * though, is the block's own business -- block-factory owns the
 * <InspectorControls> and renders it from React state. This context is the
 * channel between the item the author just focused on the canvas and the panel
 * that should describe it.
 *
 * Nothing is required to use it. A repeater that never reports an active item
 * leaves the sidebar exactly as it was, which is what every block written before
 * this existed does.
 */

import React from 'react';
import { createContext, useContext, useState, useMemo, useCallback } from '@wordpress/element';

export interface ActiveRepeaterItem {
    /** The repeater field's name, e.g. "reps". */
    field: string;
    /** Which row, by position. */
    index: number;
    /** What to call it in the panel's title, usually the row's own label. */
    label?: string;
}

interface RepeaterItemContextValue {
    active: ActiveRepeaterItem | null;
    /** Called when an item takes focus; null clears it. */
    setActive: (item: ActiveRepeaterItem | null) => void;
}

const RepeaterItemContext = createContext<RepeaterItemContextValue>({
    active: null,
    setActive: () => undefined,
});

/**
 * Hold the active item for one block.
 *
 * Scoped per block rather than globally: two blocks of the same type on one page
 * each have their own repeaters, and focusing a row in the second must not
 * repoint the first one's sidebar.
 */
export function RepeaterItemProvider({ children }: { children: React.ReactNode }): JSX.Element {
    const [active, setActiveState] = useState<ActiveRepeaterItem | null>(null);

    const setActive = useCallback((item: ActiveRepeaterItem | null) => {
        setActiveState((current) => {
            /* Same item again: leave the object identity alone so the panel does
               not remount and lose a half-typed value. */
            if (
                current &&
                item &&
                current.field === item.field &&
                current.index === item.index &&
                current.label === item.label
            ) {
                return current;
            }

            return item;
        });
    }, []);

    const value = useMemo(() => ({ active, setActive }), [active, setActive]);

    return <RepeaterItemContext.Provider value={value}>{children}</RepeaterItemContext.Provider>;
}

export function useRepeaterItem(): RepeaterItemContextValue {
    return useContext(RepeaterItemContext);
}
