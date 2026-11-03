import { buildPreviewBody, getCurrentPostId } from '../utils/preview-request';

const setStore = (select: unknown) => {
    (window as unknown as { wp?: unknown }).wp = select === undefined ? undefined : { data: { select } };
};

describe('preview request payload', () => {
    afterEach(() => setStore(undefined));

    it('includes post_id when the editor store reports a post', () => {
        setStore(() => ({ getCurrentPostId: () => 52 }));

        const body = buildPreviewBody('event-card', { a: 1 }, 'nonce1');

        expect(body.get('action')).toBe('proto_blocks_preview');
        expect(body.get('template')).toBe('event-card');
        expect(body.get('attributes')).toBe('{"a":1}');
        expect(body.get('nonce')).toBe('nonce1');
        expect(body.get('post_id')).toBe('52');
    });

    it.each([
        ['no wp.data', undefined],
        ['no editor store', () => undefined],
        ['no post yet', () => ({ getCurrentPostId: () => undefined })],
        ['zero', () => ({ getCurrentPostId: () => 0 })],
        ['negative', () => ({ getCurrentPostId: () => -3 })],
        ['not a number', () => ({ getCurrentPostId: () => 'abc' })],
        ['store throws', () => { throw new Error('no store'); }],
    ])('omits post_id for %s', (_label, select) => {
        setStore(select);

        expect(getCurrentPostId()).toBe(0);
        expect(buildPreviewBody('x', {}, 'n').has('post_id')).toBe(false);
    });
});
