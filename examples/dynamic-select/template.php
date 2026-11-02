<?php
/**
 * Block: Dynamic Select Demo
 *
 * Demonstrates select controls whose options are injected by the server
 * via `optionsSource` (wp:posts, wp:terms) instead of a static options array.
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$heading     = $attributes['heading'] ?? '';
$relatedPage = $attributes['relatedPage'] ?? '';
$category    = $attributes['category'] ?? '';

// The stored value is just an integer the author can set to any ID, so only
// print the title when the post is public or the current visitor may read it.
$pageTitle = '';
if ( $relatedPage ) {
	$pageId = (int) $relatedPage;
	if ( 'publish' === get_post_status( $pageId ) || current_user_can( 'read_post', $pageId ) ) {
		$pageTitle = get_the_title( $pageId );
	}
}

$termName = '';
if ( $category ) {
	$term = get_term( (int) $category, 'category' );
	if ( $term instanceof \WP_Term && is_taxonomy_viewable( $term->taxonomy ) ) {
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
