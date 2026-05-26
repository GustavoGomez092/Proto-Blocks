import { runBrowserCompile } from '../compiler';
import { compileTailwind } from '../engine';

jest.mock('../engine');

const mockedCompile = compileTailwind as jest.Mock;

describe('runBrowserCompile', () => {
    beforeEach(() => {
        mockedCompile.mockReset();
    });

    it('fetches inputs, compiles, and posts the resulting CSS', async () => {
        mockedCompile.mockResolvedValue('.flex{display:flex}');

        const post = jest
            .fn()
            .mockResolvedValueOnce({
                success: true,
                data: { inputCss: '@import "x";', content: '<div class="flex"></div>', hash: 'abc' },
            })
            .mockResolvedValueOnce({
                success: true,
                data: { message: 'ok', css_size_formatted: '1 KB' },
            });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(post).toHaveBeenNthCalledWith(1, '/admin-ajax.php', {
            action: 'proto_blocks_tailwind_get_compile_inputs',
            nonce: 'n',
        });
        expect(mockedCompile).toHaveBeenCalledWith('@import "x";', '<div class="flex"></div>');
        expect(post.mock.calls[1][1]).toMatchObject({
            action: 'proto_blocks_tailwind_store_css',
            nonce: 'n',
            css: '.flex{display:flex}',
            hash: 'abc',
        });
        expect(result.success).toBe(true);
    });

    it('reports failure when input fetch fails', async () => {
        const post = jest.fn().mockResolvedValueOnce({ success: false, data: { message: 'denied' } });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(result.success).toBe(false);
        expect(mockedCompile).not.toHaveBeenCalled();
    });

    it('returns failure (does not reject) when the engine throws', async () => {
        mockedCompile.mockRejectedValue(new Error('wasm OOM'));
        const post = jest.fn().mockResolvedValueOnce({
            success: true,
            data: { inputCss: '@import "x";', content: '<div></div>', hash: 'h' },
        });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(result.success).toBe(false);
        expect(result.message).toBe('wasm OOM');
        // Did not attempt to store anything.
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('reports failure when storing the compiled CSS fails', async () => {
        mockedCompile.mockResolvedValue('.flex{display:flex}');
        const post = jest
            .fn()
            .mockResolvedValueOnce({
                success: true,
                data: { inputCss: '@import "x";', content: '<div class="flex"></div>', hash: 'abc' },
            })
            .mockResolvedValueOnce({ success: false, data: { message: 'disk full' } });

        const result = await runBrowserCompile({
            ajaxUrl: '/admin-ajax.php',
            actionPrefix: 'proto_blocks_tailwind_',
            nonce: 'n',
            post,
        });

        expect(result.success).toBe(false);
        expect(result.message).toBe('disk full');
    });
});
