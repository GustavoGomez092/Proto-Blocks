<?php
/**
 * Block: Card
 *
 * A versatile card block with image, title, content, and call-to-action link.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$layout         = $attributes['layout'] ?? 'vertical';
$image_position = $attributes['imagePosition'] ?? 'top';
$show_link      = $attributes['showLink'] ?? true;
$image          = $attributes['image'] ?? [];
$title          = $attributes['title'] ?? '';
$card_content   = $attributes['content'] ?? '';
$link           = $attributes['link'] ?? [];

// Check if we're in editor preview mode (no block instance = preview)
$is_preview = ! isset( $block ) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-card',
    'proto-card',
    'proto-card--layout-' . esc_attr( $layout ),
];

if ( 'horizontal' === $layout ) {
    $classes[] = 'proto-card--image-' . esc_attr( $image_position );
}

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $classes ),
] );
?>

<article <?php echo $wrapper_attributes; ?>>
    <?php if ( ! empty( $image['url'] ) || $is_preview ) : ?>
        <figure class="proto-card__image" data-proto-field="image">
            <?php if ( ! empty( $image['url'] ) ) : ?>
                <img
                    src="<?php echo esc_url( $image['url'] ); ?>"
                    alt="<?php echo esc_attr( $image['alt'] ?? '' ); ?>"
                    loading="lazy"
                />
            <?php endif; ?>
        </figure>
    <?php endif; ?>

    <div class="proto-card__content">
        <h3 class="proto-card__title" data-proto-field="title"><?php
            if ( ! empty( $title ) ) {
                echo esc_html( $title );
            }
        ?></h3>

        <div class="proto-card__body" data-proto-field="content"><?php
            if ( ! empty( $card_content ) ) {
                echo wp_kses_post( $card_content );
            }
        ?></div>

        <?php if ( $show_link ) : ?>
            <a
                class="proto-card__link"
                href="<?php echo esc_url( $link['url'] ?? '#' ); ?>"
                data-proto-field="link"
                <?php echo ! empty( $link['target'] ) ? 'target="' . esc_attr( $link['target'] ) . '"' : ''; ?>
                <?php echo ! empty( $link['rel'] ) ? 'rel="' . esc_attr( $link['rel'] ) . '"' : ''; ?>
            ><?php echo esc_html( $link['text'] ?? __( 'Learn More', 'proto-blocks' ) ); ?></a>
        <?php endif; ?>
    </div>
</article>
