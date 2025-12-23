<?php
/**
 * Block: Tailwind Hero
 *
 * A dark hero section with gradient backgrounds and dual CTAs.
 * Styled entirely with Tailwind CSS - no style.css needed.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$show_badge = $attributes['showBadge'] ?? true;
$badge_text = $attributes['badgeText'] ?? 'Announcing our next round of funding.';
$badge_link = $attributes['badgeLink'] ?? ['url' => '#', 'text' => 'Read more'];
$heading = $attributes['heading'] ?? 'Data to enrich your online business';
$description = $attributes['description'] ?? 'Anim aute id magna aliqua ad ad non deserunt sunt. Qui irure qui lorem cupidatat commodo. Elit sunt amet fugiat veniam occaecat.';
$primary_button = $attributes['primaryButton'] ?? ['url' => '#', 'text' => 'Get started'];
$secondary_link = $attributes['secondaryLink'] ?? ['url' => '#', 'text' => 'Learn more'];
$show_secondary_link = $attributes['showSecondaryLink'] ?? true;

$wrapper_classes = [
	'wp-block-proto-blocks-tl-hero',
	'relative',
	'isolate',
	'bg-gray-900',
	'px-6',
	'py-24',
	'sm:py-32',
	'lg:px-8',
	'overflow-hidden',
];

$wrapper_attributes = get_block_wrapper_attributes([
	'class' => implode(' ', $wrapper_classes),
]);
?>

<section <?php echo $wrapper_attributes; ?>>
	<!-- Top gradient blob -->
	<div aria-hidden="true" class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80">
		<div
			style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"
			class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]">
		</div>
	</div>

	<div class="mx-auto max-w-2xl py-8 sm:py-16 lg:py-24">
		<?php if ($show_badge): ?>
			<!-- Announcement badge -->
			<div class="hidden sm:mb-8 sm:flex sm:justify-center">
				<div
					class="relative rounded-full px-3 py-1 text-sm leading-6 text-gray-400 ring-1 ring-white/10 hover:ring-white/20">
					<span data-proto-field="badgeText"><?php echo esc_html($badge_text); ?></span>
					<a href="<?php echo esc_url($badge_link['url'] ?? '#'); ?>"
						class="font-semibold text-primary-400 ml-1 no-underline hover:underline" data-proto-field="badgeLink" <?php echo !empty($badge_link['target']) ? 'target="' . esc_attr($badge_link['target']) . '"' : ''; ?>>
						<span aria-hidden="true" class="absolute inset-0"></span>
						<?php echo esc_html($badge_link['text'] ?? 'Read more'); ?>
						<span aria-hidden="true">&rarr;</span>
					</a>
				</div>
			</div>
		<?php endif; ?>

		<!-- Hero content -->
		<div class="text-center">
			<h1 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-7xl" data-proto-field="heading">
				<?php echo esc_html($heading); ?>
			</h1>

			<p class="mt-6 text-lg leading-8 text-gray-400 sm:mt-8 sm:text-xl" data-proto-field="description">
				<?php echo esc_html($description); ?>
			</p>

			<!-- CTA buttons -->
			<div class="mt-10 flex items-center justify-center gap-x-6">
				<a href="<?php echo esc_url($primary_button['url'] ?? '#'); ?>"
					class="rounded-md bg-primary-500 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 transition-colors no-underline"
					data-proto-field="primaryButton" <?php echo !empty($primary_button['target']) ? 'target="' . esc_attr($primary_button['target']) . '"' : ''; ?>><?php echo esc_html($primary_button['text'] ?? 'Get started'); ?></a>

				<?php if ($show_secondary_link): ?>
					<a href="<?php echo esc_url($secondary_link['url'] ?? '#'); ?>"
						class="text-sm font-semibold leading-6 text-white hover:text-gray-300 transition-colors no-underline"
						data-proto-field="secondaryLink" <?php echo !empty($secondary_link['target']) ? 'target="' . esc_attr($secondary_link['target']) . '"' : ''; ?>>
						<?php echo esc_html($secondary_link['text'] ?? 'Learn more'); ?>
						<span aria-hidden="true">→</span>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Bottom gradient blob -->
	<div aria-hidden="true"
		class="absolute inset-x-0 top-[calc(100%-13rem)] -z-10 transform-gpu overflow-hidden blur-3xl sm:top-[calc(100%-30rem)]">
		<div
			style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"
			class="relative left-[calc(50%+3rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%+36rem)] sm:w-[72.1875rem]">
		</div>
	</div>
</section>