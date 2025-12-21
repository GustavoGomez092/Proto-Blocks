<?php
/**
 * Block: Testimonial
 *
 * Display customer testimonials with quote, author info, and optional rating.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

// Check if we're in editor preview mode
$is_preview = ! isset( $block ) || $block === null;

$style        = $attributes['style'] ?? 'default';
$show_avatar  = $attributes['showAvatar'] ?? true;
$show_rating  = $attributes['showRating'] ?? true;
$rating       = (int) ( $attributes['rating'] ?? 5 );
$quote        = $attributes['quote'] ?? '';
$author_name  = $attributes['authorName'] ?? '';
$author_title = $attributes['authorTitle'] ?? '';
$author_image = $attributes['authorImage'] ?? [];

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-testimonial',
    'proto-testimonial',
    'proto-testimonial--style-' . esc_attr( $style ),
];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $classes ),
] );
?>

<blockquote <?php echo $wrapper_attributes; ?>>
    <?php if ( $show_rating && $rating > 0 ) : ?>
        <div class="proto-testimonial__rating" aria-label="<?php echo esc_attr( sprintf( __( 'Rating: %d out of 5 stars', 'proto-blocks' ), $rating ) ); ?>">
            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                <span class="proto-testimonial__star <?php echo $i <= $rating ? 'is-filled' : ''; ?>" aria-hidden="true">
                    <?php echo $i <= $rating ? '★' : '☆'; ?>
                </span>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <div class="proto-testimonial__quote" data-proto-field="quote">
        <?php echo wp_kses_post( $quote ); ?>
    </div>

    <footer class="proto-testimonial__footer">
        <?php if ( $show_avatar && ( ! empty( $author_image['url'] ) || $is_preview ) ) : ?>
            <figure class="proto-testimonial__avatar" data-proto-field="authorImage">
                <?php if ( ! empty( $author_image['url'] ) ) : ?>
                    <img
                        src="<?php echo esc_url( $author_image['url'] ); ?>"
                        alt="<?php echo esc_attr( $author_name ); ?>"
                        loading="lazy"
                    />
                <?php endif; ?>
            </figure>
        <?php endif; ?>

        <div class="proto-testimonial__author">
            <cite class="proto-testimonial__name" data-proto-field="authorName"><?php
                echo esc_html( $author_name );
            ?></cite>

            <span class="proto-testimonial__title" data-proto-field="authorTitle"><?php
                echo esc_html( $author_title );
            ?></span>
        </div>
    </footer>
</blockquote>
