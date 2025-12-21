<?php
/**
 * Block: Accordion
 *
 * Collapsible accordion sections with customizable content using the Interactivity API.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$items          = $attributes['items'] ?? [];
$allow_multiple = $attributes['allowMultiple'] ?? false;
$first_open     = $attributes['firstOpen'] ?? true;
$icon_position  = $attributes['iconPosition'] ?? 'right';

// Check if we're in editor preview mode (no block instance = preview)
$is_preview = ! isset( $block ) || $block === null;

// For preview, show placeholder if no items
if ( empty( $items ) ) {
    if ( $is_preview ) {
        $items = [
            [ 'id' => 'preview-1', 'title' => 'Accordion Item 1', 'content' => 'Click to edit this content...' ],
            [ 'id' => 'preview-2', 'title' => 'Accordion Item 2', 'content' => 'Add more items using the repeater...' ],
        ];
    } else {
        return;
    }
}

// Generate a unique ID prefix for this block
$block_id = $is_preview ? 'preview-' . uniqid() : 'accordion-' . ( $block->context['postId'] ?? uniqid() );

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-accordion',
    'proto-accordion',
    'proto-accordion--icon-' . esc_attr( $icon_position ),
];

// Prepare Interactivity API state
$context = [
    'allowMultiple' => $allow_multiple,
    'openItems'     => $first_open ? [ 0 ] : [],
];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'                     => implode( ' ', $classes ),
    'data-wp-interactive'       => 'proto-blocks/accordion',
    'data-wp-context'           => wp_json_encode( $context ),
] );
?>

<div <?php echo $wrapper_attributes; ?> data-proto-repeater="items">
    <?php foreach ( $items as $index => $item ) : ?>
        <?php
        $item_id      = $block_id . '-' . $index;
        $is_open      = $first_open && 0 === $index;
        $title        = $item['title'] ?? '';
        $item_content = $item['content'] ?? '';
        ?>
        <div
            class="proto-accordion__item"
            data-proto-repeater-item
            data-wp-context='{"index": <?php echo $index; ?>}'
            data-wp-class--is-open="state.isItemOpen"
        >
            <h3 class="proto-accordion__header">
                <button
                    type="button"
                    class="proto-accordion__trigger"
                    id="<?php echo esc_attr( $item_id ); ?>-trigger"
                    aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
                    aria-controls="<?php echo esc_attr( $item_id ); ?>-panel"
                    data-wp-on--click="actions.toggle"
                    data-wp-bind--aria-expanded="state.isItemOpen"
                >
                    <span class="proto-accordion__title" data-proto-field="title">
                        <?php echo esc_html( $title ); ?>
                    </span>
                    <span class="proto-accordion__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                </button>
            </h3>

            <div
                id="<?php echo esc_attr( $item_id ); ?>-panel"
                class="proto-accordion__panel"
                role="region"
                aria-labelledby="<?php echo esc_attr( $item_id ); ?>-trigger"
                data-wp-bind--hidden="!state.isItemOpen"
                <?php echo ! $is_open ? 'hidden' : ''; ?>
            >
                <div class="proto-accordion__content" data-proto-field="content">
                    <?php echo wp_kses_post( $item_content ); ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
