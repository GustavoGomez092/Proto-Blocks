<?php
/**
 * Block: Hero Section
 *
 * A full-width hero section with background image, customizable colors, and nested content.
 *
 * @var array    $attributes Block attributes.
 * @var string   $innerBlocksContent Nested blocks HTML (inner-blocks field).
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$title              = $attributes['title'] ?? '';
$subtitle           = $attributes['subtitle'] ?? '';
$background_image   = $attributes['backgroundImage'] ?? [];
// Colour controls land inside style="": validate as hex with a safe default, never merely escape.
$background_color   = proto_blocks_sanitize_color( $attributes['backgroundColor'] ?? '', '#1e1e1e' );
$text_color         = proto_blocks_sanitize_color( $attributes['textColor'] ?? '', '#ffffff' );
// Numeric controls: cast and clamp to the control's range.
$overlay_opacity    = max( 0, min( 100, (int) ( $attributes['overlayOpacity'] ?? 70 ) ) );
$min_height         = max( 20, min( 100, (int) ( $attributes['minHeight'] ?? 60 ) ) );
// Select/radio values are not validated against `options` on the server: allowlist before use in a class.
$content_alignment  = $attributes['contentAlignment'] ?? 'center';
$content_alignment  = in_array( $content_alignment, [ 'left', 'center', 'right' ], true ) ? $content_alignment : 'center';
$vertical_alignment = $attributes['verticalAlignment'] ?? 'center';
$vertical_alignment = in_array( $vertical_alignment, [ 'top', 'center', 'bottom' ], true ) ? $vertical_alignment : 'center';

// Check if we're in editor preview mode
$is_preview = ! isset( $block ) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-hero',
    'proto-hero',
    'proto-hero--align-' . sanitize_html_class( $content_alignment ),
    'proto-hero--valign-' . sanitize_html_class( $vertical_alignment ),
];

// Build inline styles
$overlay_rgba = proto_blocks_hex_to_rgba( $background_color, $overlay_opacity / 100 );
$styles = [
    'min-height: ' . $min_height . 'vh',
    'color: ' . $text_color,
];

// Resolve the background from the attachment id when there is one; fall back
// to the stored URL through proto_blocks_css_url(), which percent-encodes the
// characters that could close url() and inject further declarations.
$background_id  = (int) ( $background_image['id'] ?? 0 );
$background_url = $background_id ? (string) wp_get_attachment_image_url( $background_id, 'full' ) : '';
if ( '' === $background_url && ! empty( $background_image['url'] ) ) {
    $background_url = (string) $background_image['url'];
}
$background_css = '' !== $background_url ? proto_blocks_css_url( $background_url ) : '';
if ( '' !== $background_css ) {
    $styles[] = 'background-image: ' . $background_css;
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
            <?php echo $innerBlocksContent ?? ''; ?>
        </div>
    </div>
</section>
