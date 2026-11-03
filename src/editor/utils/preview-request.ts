type EditorStore = { getCurrentPostId?: () => unknown } | undefined | null;

/**
 * The post being edited, or 0 when the editor store has none (site editor,
 * widgets screen, store not registered). Reads the `wp.data` global the editor
 * already provides.
 */
export function getCurrentPostId(): number {
    try {
        const wpData = (window as unknown as {
            wp?: { data?: { select?: (store: string) => EditorStore } };
        }).wp?.data;
        const id = Number(wpData?.select?.('core/editor')?.getCurrentPostId?.());
        return Number.isInteger(id) && id > 0 ? id : 0;
    } catch {
        return 0;
    }
}

/**
 * Form body of the admin-ajax `proto_blocks_preview` request. `post_id` is
 * included only when the editor reports a post, so field-driven blocks render
 * that post's own values in the preview.
 */
export function buildPreviewBody(
    template: string,
    attributes: unknown,
    nonce: string,
    postId: number = getCurrentPostId()
): URLSearchParams {
    const body = new URLSearchParams({
        action: 'proto_blocks_preview',
        template,
        attributes: JSON.stringify(attributes),
        nonce,
    });
    if (postId > 0) {
        body.set('post_id', String(postId));
    }
    return body;
}
