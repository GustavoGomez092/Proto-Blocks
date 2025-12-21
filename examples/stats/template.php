<?php
/**
 * Block: Stats Counter
 *
 * Display statistics and numbers with labels in a grid layout.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$stats         = $attributes['stats'] ?? [];
$columns       = $attributes['columns'] ?? 4;
$style         = $attributes['style'] ?? 'default';
$number_size   = $attributes['numberSize'] ?? 48;
$show_dividers = $attributes['showDividers'] ?? false;

// Check if we're in editor preview mode
$is_preview = ! isset( $block ) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-stats',
    'proto-stats',
    'proto-stats--style-' . esc_attr( $style ),
    'proto-stats--cols-' . esc_attr( $columns ),
];

if ( $show_dividers ) {
    $classes[] = 'proto-stats--dividers';
}

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $classes ),
    'style' => '--proto-stats-number-size: ' . esc_attr( $number_size ) . 'px; --proto-stats-columns: ' . esc_attr( $columns ) . ';',
] );

// Default stats for preview
if ( empty( $stats ) && $is_preview ) {
    $stats = [
        [ 'id' => '1', 'number' => '150', 'suffix' => '+', 'label' => 'Happy Clients' ],
        [ 'id' => '2', 'number' => '500', 'suffix' => 'K', 'label' => 'Downloads' ],
        [ 'id' => '3', 'number' => '99', 'suffix' => '%', 'label' => 'Satisfaction' ],
        [ 'id' => '4', 'prefix' => '$', 'number' => '2.5', 'suffix' => 'M', 'label' => 'Revenue' ],
    ];
}
?>

<div <?php echo $wrapper_attributes; ?>>
    <div class="proto-stats__grid" data-proto-repeater="stats">
        <?php foreach ( $stats as $index => $stat ) : ?>
            <div class="proto-stats__item" data-proto-repeater-item>
                <div class="proto-stats__number-wrapper">
                    <?php if ( ! empty( $stat['prefix'] ) || $is_preview ) : ?>
                        <span class="proto-stats__prefix" data-proto-field="prefix"><?php
                            echo esc_html( $stat['prefix'] ?? '' );
                        ?></span>
                    <?php endif; ?>

                    <span class="proto-stats__number" data-proto-field="number"><?php
                        echo esc_html( $stat['number'] ?? '0' );
                    ?></span>

                    <?php if ( ! empty( $stat['suffix'] ) || $is_preview ) : ?>
                        <span class="proto-stats__suffix" data-proto-field="suffix"><?php
                            echo esc_html( $stat['suffix'] ?? '' );
                        ?></span>
                    <?php endif; ?>
                </div>

                <span class="proto-stats__label" data-proto-field="label"><?php
                    echo esc_html( $stat['label'] ?? '' );
                ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
