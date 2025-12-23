<?php
/**
 * Block: Card (Tailwind Example)
 *
 * A versatile card block styled with Tailwind CSS utilities.
 * Demonstrates how to build Proto Blocks using Tailwind.
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

// Base card classes using Tailwind
$card_classes = [
	'wp-block-proto-blocks-card',
	'proto-card',
	// Tailwind utilities for base card
	'flex',
	'overflow-hidden',
	'bg-white',
	'rounded-lg',
	'shadow-md',
	'hover:shadow-xl',
	'hover:-translate-y-0.5',
	'transition-all',
	'duration-300',
];

// Layout-specific classes
if ( 'horizontal' === $layout ) {
	$card_classes[] = 'flex-row';
	$card_classes[] = 'proto-card--layout-horizontal';
	if ( 'right' === $image_position ) {
		$card_classes[] = 'flex-row-reverse';
	}
} elseif ( 'overlay' === $layout ) {
	$card_classes[] = 'relative';
	$card_classes[] = 'min-h-[300px]';
	$card_classes[] = 'proto-card--layout-overlay';
} else {
	$card_classes[] = 'flex-col';
	$card_classes[] = 'proto-card--layout-vertical';
}

$wrapper_attributes = get_block_wrapper_attributes( [
	'class' => implode( ' ', $card_classes ),
] );

// Image classes
$image_classes = [
	'proto-card__image',
	'm-0',
	'overflow-hidden',
];

if ( 'horizontal' === $layout ) {
	$image_classes[] = 'w-2/5';
	$image_classes[] = 'flex-shrink-0';
} elseif ( 'overlay' === $layout ) {
	$image_classes[] = 'absolute';
	$image_classes[] = 'inset-0';
}

// Content classes
$content_classes = [
	'proto-card__content',
	'flex',
	'flex-col',
	'gap-4',
	'p-6',
	'flex-1',
];

if ( 'overlay' === $layout ) {
	$content_classes[] = 'relative';
	$content_classes[] = 'z-10';
	$content_classes[] = 'justify-end';
	$content_classes[] = 'text-white';
}
?>

<article <?php echo $wrapper_attributes; ?>>
	<?php if ( ! empty( $image['url'] ) || $is_preview ) : ?>
	<figure class="<?php echo esc_attr( implode( ' ', $image_classes ) ); ?>" data-proto-field="image">
		<?php if ( ! empty( $image['url'] ) ) : ?>
		<img
			src="<?php echo esc_url( $image['url'] ); ?>"
			alt="<?php echo esc_attr( $image['alt'] ?? '' ); ?>"
			class="block w-full h-auto object-cover transition-transform duration-300 hover:scale-[1.02] <?php echo 'overlay' === $layout ? 'h-full' : ''; ?>"
			loading="lazy"
		/>
		<?php endif; ?>
	</figure>
	<?php endif; ?>

	<div class="<?php echo esc_attr( implode( ' ', $content_classes ) ); ?>">
		<h3
			class="proto-card__title m-0 text-xl font-semibold leading-tight <?php echo 'overlay' === $layout ? 'text-white' : 'text-gray-900'; ?>"
			data-proto-field="title"
		><?php
			if ( ! empty( $title ) ) {
				echo esc_html( $title );
			}
		?></h3>

		<div
			class="proto-card__body m-0 leading-relaxed <?php echo 'overlay' === $layout ? 'text-white/90' : 'text-gray-600'; ?>"
			data-proto-field="content"
		><?php
			if ( ! empty( $card_content ) ) {
				echo wp_kses_post( $card_content );
			}
		?></div>

		<?php if ( $show_link ) : ?>
		<a
			class="proto-card__link inline-flex items-center gap-2 mt-auto px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white no-underline rounded font-medium transition-colors duration-200"
			href="<?php echo esc_url( $link['url'] ?? '#' ); ?>"
			data-proto-field="link"
			<?php echo ! empty( $link['target'] ) ? 'target="' . esc_attr( $link['target'] ) . '"' : ''; ?>
			<?php echo ! empty( $link['rel'] ) ? 'rel="' . esc_attr( $link['rel'] ) . '"' : ''; ?>
		><?php echo esc_html( $link['text'] ?? __( 'Learn More', 'proto-blocks' ) ); ?></a>
		<?php endif; ?>
	</div>
</article>
