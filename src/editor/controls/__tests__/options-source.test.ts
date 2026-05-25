import apiFetch from '@wordpress/api-fetch';
import { fetchControlOptions } from '../options-source';

jest.mock('@wordpress/api-fetch');

const mockedApiFetch = apiFetch as unknown as jest.Mock;

describe('fetchControlOptions', () => {
    beforeEach(() => {
        mockedApiFetch.mockReset();
    });

    it('requests the options endpoint with source and JSON-encoded args', async () => {
        mockedApiFetch.mockResolvedValue({ options: [], total: 0 });

        await fetchControlOptions('wp:posts', { post_type: 'page' });

        expect(mockedApiFetch).toHaveBeenCalledTimes(1);
        const path = mockedApiFetch.mock.calls[0][0].path as string;
        expect(path).toContain('/proto-blocks/v1/controls/options');
        expect(path).toContain('source=wp%3Aposts');
        expect(path).toContain(encodeURIComponent(JSON.stringify({ post_type: 'page' })));
    });

    it('maps {key,label} responses to {value,label}', async () => {
        mockedApiFetch.mockResolvedValue({
            options: [
                { key: '1', label: 'One' },
                { key: '2', label: 'Two' },
            ],
            total: 2,
        });

        const result = await fetchControlOptions('wp:posts');

        expect(result).toEqual([
            { value: '1', label: 'One' },
            { value: '2', label: 'Two' },
        ]);
    });

    it('returns an empty array when the response has no options', async () => {
        mockedApiFetch.mockResolvedValue({});

        const result = await fetchControlOptions('currencies');

        expect(result).toEqual([]);
    });

    it('propagates apiFetch rejections to the caller', async () => {
        mockedApiFetch.mockRejectedValue(new Error('Network error'));

        await expect(fetchControlOptions('wp:posts')).rejects.toThrow('Network error');
    });
});
