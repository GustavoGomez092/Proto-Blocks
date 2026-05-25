/**
 * Fetches dynamic control options from the Proto-Blocks REST API.
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export interface SelectOption {
    value: string;
    label: string;
}

/** The raw option shape returned by the server provider. */
export interface RawOption {
    key: string;
    label: string;
}

interface OptionsResponse {
    options?: RawOption[];
    // Total count from the server; reserved for future pagination, not yet consumed.
    total?: number;
}

const ENDPOINT = '/proto-blocks/v1/controls/options';

/**
 * Fetch options for a dynamic select control.
 *
 * @param source Source identifier, e.g. "wp:posts".
 * @param args   Source-specific arguments, forwarded to the server provider.
 */
export async function fetchControlOptions(
    source: string,
    args: Record<string, unknown> = {}
): Promise<SelectOption[]> {
    const path = addQueryArgs(ENDPOINT, {
        source,
        args: JSON.stringify(args),
    });

    const response = await apiFetch<OptionsResponse>({ path });

    return (response.options || []).map((opt) => ({
        value: opt.key,
        label: opt.label,
    }));
}
