/**
 * A control that stores an ordered list of option keys.
 *
 * Selection is core's FormTokenField (the control used for post categories and
 * tags, so it is already familiar and keyboard accessible). Ordering is a
 * dnd-kit sortable list beneath it, matching RepeaterField's drag pattern.
 *
 * Options come from the same pipeline `select` uses: static `options`, or a
 * server `optionsSource`. Because the server clamps per_page to 200 and real
 * catalogues exceed that, typing in the field re-queries the server rather
 * than filtering only what happened to load on mount.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { FormTokenField, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { fetchControlOptions, SelectOption } from './options-source';
import {
    buildLabelIndex,
    keysToLabels,
    labelsToKeys,
    mergeOptions,
    suggestionsFor,
} from './multiselect-options';
import { useDebouncedCallback } from '../utils/debounce';

interface MultiSelectControlProps {
    label: string;
    value: string[];
    options?: Array<{ key: string; label: string }>;
    source?: string;
    sourceArgs?: Record<string, unknown>;
    onChange: (value: string[]) => void;
}

interface SortableTokenProps {
    id: string;
    label: string;
    onRemove: (id: string) => void;
}

function SortableToken({ id, label, onRemove }: SortableTokenProps): JSX.Element {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            className={`proto-blocks-multiselect__item${
                isDragging ? ' is-dragging' : ''
            }`}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
        >
            <button
                type="button"
                className="proto-blocks-multiselect__handle"
                aria-label={__('Reorder', 'proto-blocks')}
                {...attributes}
                {...listeners}
            >
                ⠿
            </button>
            <span className="proto-blocks-multiselect__label">{label}</span>
            <button
                type="button"
                className="components-button is-link is-destructive is-small"
                onClick={() => onRemove(id)}
            >
                {__('Remove', 'proto-blocks')}
            </button>
        </li>
    );
}

export function MultiSelectControl({
    label,
    value,
    options: staticOptions,
    source,
    sourceArgs = {},
    onChange,
}: MultiSelectControlProps): JSX.Element {
    const selected = useMemo(() => (Array.isArray(value) ? value : []), [value]);

    const [known, setKnown] = useState<SelectOption[]>(() =>
        (staticOptions ?? []).map((o) => ({ value: o.key, label: o.label }))
    );
    const [loading, setLoading] = useState<boolean>(Boolean(source));
    const [error, setError] = useState<string | null>(null);

    // Serialised so the effect re-runs only when the args actually change --
    // sourceArgs is rebuilt each render from static block.json config.
    const argsKey = JSON.stringify(sourceArgs);
    const requestId = useRef(0);

    const load = useCallback(
        (search: string) => {
            if (!source) {
                return;
            }

            const id = ++requestId.current;
            setLoading(true);
            setError(null);

            fetchControlOptions(source, {
                ...JSON.parse(argsKey),
                ...(search ? { search } : {}),
            })
                .then((incoming) => {
                    // Ignore a response that a newer keystroke has superseded.
                    if (id !== requestId.current) {
                        return;
                    }
                    // Accumulate rather than replace: a token for a key that
                    // has fallen out of the current page must keep its label.
                    setKnown((previous) => mergeOptions(previous, incoming));
                    setLoading(false);
                })
                .catch((err) => {
                    if (id !== requestId.current) {
                        return;
                    }
                    // eslint-disable-next-line no-console
                    console.error('Proto-Blocks: failed to load control options', err);
                    setError(__('Could not load options.', 'proto-blocks'));
                    setLoading(false);
                });
        },
        [source, argsKey]
    );

    useEffect(() => {
        load('');
    }, [load]);

    // tsconfig has `strict: false`, so a typed single-parameter callback
    // satisfies the hook's generic without a cast -- block-factory.tsx passes
    // `(attrs: BlockAttributes) => ...` the same way.
    const debouncedSearch = useDebouncedCallback(load, 300);

    const index = useMemo(() => buildLabelIndex(known), [known]);
    const tokens = useMemo(() => keysToLabels(selected, index), [selected, index]);
    const suggestions = useMemo(
        () => suggestionsFor(index, selected),
        [index, selected]
    );

    const handleTokensChange = useCallback(
        (next: (string | { value: string })[]) => {
            const labels = next.map((token) =>
                typeof token === 'string' ? token : token.value
            );
            onChange(labelsToKeys(labels, index, selected));
        },
        [index, selected, onChange]
    );

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;
            if (!over || active.id === over.id) {
                return;
            }
            const oldIndex = selected.indexOf(String(active.id));
            const newIndex = selected.indexOf(String(over.id));
            if (oldIndex === -1 || newIndex === -1) {
                return;
            }
            onChange(arrayMove(selected, oldIndex, newIndex));
        },
        [selected, onChange]
    );

    const handleRemove = useCallback(
        (key: string) => onChange(selected.filter((k) => k !== key)),
        [selected, onChange]
    );

    return (
        <div className="proto-blocks-multiselect">
            <FormTokenField
                label={label}
                value={tokens}
                suggestions={suggestions}
                onChange={handleTokensChange}
                onInputChange={debouncedSearch}
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                __experimentalExpandOnFocus
            />

            {loading && <Spinner />}
            {error && <p className="components-base-control__help">{error}</p>}

            {selected.length > 1 && (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext
                        items={selected}
                        strategy={verticalListSortingStrategy}
                    >
                        <ul className="proto-blocks-multiselect__list">
                            {selected.map((key, position) => (
                                <SortableToken
                                    key={key}
                                    id={key}
                                    label={tokens[position]}
                                    onRemove={handleRemove}
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}
        </div>
    );
}
