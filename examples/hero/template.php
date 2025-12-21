<?php
/**
 * Block: Hero Section
 *
 * A full-width hero section with background image, customizable colors, and nested content.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

// Ensure $content is defined (may not be set in preview mode)
$content = $content ?? '';

$title              = $attributes['title'] ?? '';
$subtitle           = $attributes['subtitle'] ?? '';
$background_image   = $attributes['backgroundImage'] ?? [];
$background_color   = $attributes['backgroundColor'] ?? '#1e1e1e';
$overlay_opacity    = $attributes['overlayOpacity'] ?? 70;
$text_color         = $attributes['textColor'] ?? '#ffffff';
$content_alignment  = $attributes['contentAlignment'] ?? 'center';
$min_height         = $attributes['minHeight'] ?? 60;
$vertical_alignment = $attributes['verticalAlignment'] ?? 'center';

// Check if we're in editor preview mode
$is_preview = ! isset( $block ) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-hero',
    'proto-hero',
    'proto-hero--align-' . esc_attr( $content_alignment ),
    'proto-hero--valign-' . esc_attr( $vertical_alignment ),
];

// Build inline styles
$overlay_rgba = proto_blocks_hex_to_rgba( $background_color, $overlay_opacity / 100 );
$styles = [
    'min-height: ' . esc_attr( $min_height ) . 'vh',
    'color: ' . esc_attr( $text_color ),
];

if ( ! empty( $background_image['url'] ) ) {
    $styles[] = 'background-image: url(' . esc_url( $background_image['url'] ) . ')';
}

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $classes ),
    'style' => implode( '; ', $styles ),
] );
?>

<section <?php echo $wrapper_attributes; ?>>
    <div class="proto-hero__overlay" style="background-color: <?php echo esc_attr( $overlay_rgba ); ?>;"></div>

    <div class="proto-hero__content">
        <h1 class="proto-hero__title" data-proto-field="title"><?php
            if ( ! empty( $title ) ) {
                echo wp_kses_post( $title );
            }
        ?></h1>

        <?php if ( ! empty( $subtitle ) || $is_preview ) : ?>
            <p class="proto-hero__subtitle" data-proto-field="subtitle"><?php
                if ( ! empty( $subtitle ) ) {
                    echo wp_kses_post( $subtitle );
                }
            ?></p>
        <?php endif; ?>

        <div class="proto-hero__inner-blocks" data-proto-inner-blocks>
            <?php echo $content; ?>
        </div>
    </div>
</section>
