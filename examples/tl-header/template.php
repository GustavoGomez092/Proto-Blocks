<?php
/**
 * Template for Header Navigation Block
 *
 * @var array $attributes Block attributes
 */

$fixed_class = !empty($attributes['fixedPosition']) ? 'fixed' : 'relative';
$show_cta = $attributes['showCta'] ?? true;
$nav_items = $attributes['navItems'] ?? [];
$cta_button = $attributes['ctaButton'] ?? ['url' => '#', 'text' => 'Get started'];
$logo = $attributes['logo'] ?? [];
?>
<nav class="bg-white <?php echo esc_attr($fixed_class); ?> w-full z-20 top-0 start-0 border-b border-gray-200">
  <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
    <!-- Logo and Site Title -->
    <div class="flex items-center space-x-3 rtl:space-x-reverse">
      <?php if (!empty($logo['url'])): ?>
      <img data-proto-field="logo" src="<?php echo esc_url($logo['url']); ?>" class="h-8"
        alt="<?php echo esc_attr($logo['alt'] ?? ''); ?>" />
      <?php else: ?>
      <img data-proto-field="logo" src="" class="h-8" alt="Logo" />
      <?php endif; ?>
      <span data-proto-field="siteTitle" class="self-center text-xl text-gray-900 font-semibold whitespace-nowrap">Site Name</span>
    </div>

    <!-- CTA and Mobile Menu Button -->
    <div class="flex md:order-2 space-x-3 md:space-x-0 rtl:space-x-reverse">
      <?php if ($show_cta): ?>
      <a href="<?php echo esc_url($cta_button['url'] ?? '#'); ?>"
        <?php echo !empty($cta_button['target']) ? 'target="' . esc_attr($cta_button['target']) . '"' : ''; ?>
        <?php echo !empty($cta_button['rel']) ? 'rel="' . esc_attr($cta_button['rel']) . '"' : ''; ?>
        class="text-white bg-primary-600 hover:bg-primary-700 focus:ring-4 focus:ring-primary-300 font-medium rounded-lg text-sm px-4 py-2 focus:outline-none"
        data-proto-field="ctaButton"><?php echo esc_html($cta_button['text'] ?? 'Get started'); ?></a>
      <?php endif; ?>

      <!-- Mobile menu button -->
      <button data-collapse-toggle="navbar-header" type="button"
        class="inline-flex items-center p-2 w-10 h-10 justify-center text-sm text-gray-500 rounded-lg md:hidden hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200"
        aria-controls="navbar-header" aria-expanded="false">
        <span class="sr-only">Open main menu</span>
        <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 17 14">
          <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M1 1h15M1 7h15M1 13h15" />
        </svg>
      </button>
    </div>

    <!-- Navigation Menu -->
    <div class="items-center justify-between hidden w-full md:flex md:w-auto md:order-1" id="navbar-header">
      <ul
        class="flex flex-col font-medium p-4 md:p-0 mt-4 border border-gray-100 rounded-lg bg-gray-50 md:space-x-8 rtl:space-x-reverse md:flex-row md:mt-0 md:border-0 md:bg-white"
        data-proto-repeater="navItems">
        <?php
        // Default items for preview
        if (empty($nav_items)) {
            $nav_items = [
                ['label' => 'Home', 'url' => '#'],
                ['label' => 'About', 'url' => '#'],
                ['label' => 'Services', 'url' => '#'],
                ['label' => 'Contact', 'url' => '#'],
            ];
        }
        ?>
        <?php foreach ($nav_items as $index => $item):
            $label = $item['label'] ?? 'Link';
            $url = $item['url'] ?? '#';
            $is_first = $index === 0;
            $link_class = $is_first
                ? 'block py-2 px-3 text-white bg-primary-600 rounded md:bg-transparent md:text-primary-600 md:p-0'
                : 'block py-2 px-3 text-gray-900 rounded hover:bg-gray-100 md:hover:bg-transparent md:hover:text-primary-600 md:p-0';
        ?>
        <li data-proto-repeater-item>
          <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($link_class); ?>"
            <?php echo $is_first ? 'aria-current="page"' : ''; ?>><span
              data-proto-field="label"><?php echo esc_html($label); ?></span></a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>

<!-- Mobile menu toggle script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toggleButton = document.querySelector('[data-collapse-toggle="navbar-header"]');
  const navbar = document.getElementById('navbar-header');

  if (toggleButton && navbar) {
    toggleButton.addEventListener('click', function() {
      navbar.classList.toggle('hidden');
      const expanded = toggleButton.getAttribute('aria-expanded') === 'true';
      toggleButton.setAttribute('aria-expanded', !expanded);
    });
  }
});
</script>
