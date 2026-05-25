/**
 * A select control whose options are loaded from the server on mount.
 */

import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchControlOptions, SelectOption } from './options-source';

interface DynamicSelectControlProps {
    label: string;
    value: string;
    source: string;
    sourceArgs?: Record<string, unknown>;
    onChange: (value: string) => void;
}

export function DynamicSelectControl({
    label,
    value,
    source,
    sourceArgs = {},
    onChange,
}: DynamicSelectControlProps): JSX.Element {
    const [options, setOptions] = useState<SelectOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Serialize args so the effect re-runs only when they actually change
    // (sourceArgs is recreated each render from static block.json config, where
    // key order is stable, so the serialized form is a reliable change signal).
    const argsKey = JSON.stringify(sourceArgs);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);

        fetchControlOptions(source, sourceArgs)
            .then((opts) => {
                if (active) {
                    setOptions(opts);
                    setLoading(false);
                }
            })
            .catch((err) => {
                if (active) {
                    // eslint-disable-next-line no-console
                    console.error('Proto-Blocks: failed to load control options', err);
                    setError(__('Could not load options.', 'proto-blocks'));
                    setLoading(false);
                }
            });

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [source, argsKey]);

    if (loading) {
        return (
            <div className="proto-blocks-dynamic-select is-loading">
                <label className="components-base-control__label">{label}</label>
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div className="proto-blocks-dynamic-select has-error">
                <label className="components-base-control__label">{label}</label>
                <p className="components-base-control__help">{error}</p>
            </div>
        );
    }

    return (
        <SelectControl
            label={label}
            value={value}
            options={[
                { value: '', label: __('— Select —', 'proto-blocks') },
                ...options,
            ]}
            onChange={onChange}
            __next40pxDefaultSize
            __nextHasNoMarginBottom
        />
    );
}
