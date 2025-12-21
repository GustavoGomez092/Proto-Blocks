<?php
/**
 * Block: Call to Action
 *
 * A prominent call-to-action section with customizable styling.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$title            = $attributes['title'] ?? '';
$description      = $attributes['description'] ?? '';
$link             = $attributes['link'] ?? [];
$background_color = $attributes['backgroundColor'] ?? '';
$text_color       = $attributes['textColor'] ?? '';
$button_style     = $attributes['buttonStyle'] ?? 'primary';
$layout           = $attributes['layout'] ?? 'centered';
$show_icon        = $attributes['showIcon'] ?? true;
$full_width       = $attributes['fullWidth'] ?? false;

// Check if we're in editor preview mode
$is_preview = ! isset( $block ) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-cta',
    'proto-cta',
    'proto-cta--layout-' . esc_attr( $layout ),
    'proto-cta--button-' . esc_attr( $button_style ),
];

if ( $full_width ) {
    $classes[] = 'proto-cta--button-full';
}

// Build inline styles
$styles = [];
if ( ! empty( $background_color ) ) {
    $styles[] = 'background-color: ' . esc_attr( $background_color );
}
if ( ! empty( $text_color ) ) {
    $styles[] = 'color: ' . esc_attr( $text_color );
}

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $classes ),
    'style' => ! empty( $styles ) ? implode( '; ', $styles ) : null,
] );

// Arrow icon SVG
$arrow_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M13.293 5.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L17.586 13H4a1 1 0 110-2h13.586l-4.293-4.293a1 1 0 010-1.414z"/></svg>';
?>

<div <?php echo $wrapper_attributes; ?>>
    <div class="proto-cta__content">
        <h2 class="proto-cta__title" data-proto-field="title"><?php
            if ( ! empty( $title ) ) {
                echo wp_kses_post( $title );
            } elseif ( $is_preview ) {
                echo 'Ready to Get Started?';
            }
        ?></h2>

        <?php if ( ! empty( $description ) ) : ?>
            <p class="proto-cta__description"><?php echo esc_html( $description ); ?></p>
        <?php endif; ?>
    </div>

    <div class="proto-cta__action">
        <a
            class="proto-cta__button"
            href="<?php echo esc_url( $link['url'] ?? '#' ); ?>"
            data-proto-field="link"
            <?php echo ! empty( $link['target'] ) ? 'target="' . esc_attr( $link['target'] ) . '"' : ''; ?>
            <?php echo ! empty( $link['rel'] ) ? 'rel="' . esc_attr( $link['rel'] ) . '"' : ''; ?>
        >
            <span class="proto-cta__button-text"><?php
                echo esc_html( $link['text'] ?? __( 'Get Started', 'proto-blocks' ) );
            ?></span>
            <?php if ( $show_icon ) : ?>
                <span class="proto-cta__button-icon"><?php echo $arrow_icon; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>
