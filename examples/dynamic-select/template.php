<?php
/**
 * Block: Dynamic Select Demo
 *
 * Demonstrates select controls whose options are injected by the server
 * via `optionsSource` (wp:posts, wp:terms) instead of a static options array.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$heading     = $attributes['heading'] ?? '';
$relatedPage = $attributes['relatedPage'] ?? '';
$category    = $attributes['category'] ?? '';

$pageTitle = $relatedPage ? get_the_title( (int) $relatedPage ) : '';

$termName = '';
if ( $category ) {
	$term = get_term( (int) $category );
	if ( $term instanceof \WP_Term ) {
		$termName = $term->name;
	}
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'dynamic-select-demo' ] ); ?>>
	<h3 data-proto-field="heading"><?php echo esc_html( $heading ); ?></h3>
	<?php if ( $pageTitle ) : ?>
		<p><?php echo esc_html__( 'Related page:', 'proto-blocks' ); ?> <?php echo esc_html( $pageTitle ); ?></p>
	<?php endif; ?>
	<?php if ( $termName ) : ?>
		<p><?php echo esc_html__( 'Category:', 'proto-blocks' ); ?> <?php echo esc_html( $termName ); ?></p>
	<?php endif; ?>
</div>
