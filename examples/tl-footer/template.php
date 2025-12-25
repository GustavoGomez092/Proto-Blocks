<?php
/**
 * Template for Tailwind Footer Block
 *
 * A responsive footer with logo, description, navigation columns, contact info, and copyright.
 * Styled entirely with Tailwind CSS.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

// Controls
$show_logo = $attributes['showLogo'] ?? true;
$show_column1 = $attributes['showColumn1'] ?? true;
$show_column2 = $attributes['showColumn2'] ?? true;

// Logo and description
$logo = $attributes['logo'] ?? [];
$description = $attributes['description'] ?? 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s.';

// Column 1 - Navigation
$column1_title = $attributes['column1Title'] ?? 'Company';
$column1_links = $attributes['column1Links'] ?? [];

// Column 2 - Contact
$column2_title = $attributes['column2Title'] ?? 'Get in touch';
$phone = $attributes['phone'] ?? '+1-212-456-7890';
$email = $attributes['email'] ?? 'contact@example.com';

// Copyright
$copyright_text = $attributes['copyrightText'] ?? 'Copyright 2024';
$copyright_link = $attributes['copyrightLink'] ?? ['url' => '#', 'text' => 'Your Company'];

// Default navigation links for preview
if (empty($column1_links)) {
    $column1_links = [
        ['label' => 'Home', 'url' => '#'],
        ['label' => 'About us', 'url' => '#'],
        ['label' => 'Contact us', 'url' => '#'],
        ['label' => 'Privacy policy', 'url' => '#'],
    ];
}

$wrapper_classes = [
    'wp-block-proto-blocks-tl-footer',
    'px-6',
    'md:px-16',
    'lg:px-24',
    'xl:px-32',
    'pt-8',
    'w-full',
    'text-gray-500',
    'bg-white',
];

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => implode(' ', $wrapper_classes),
]);
?>

<footer <?php echo $wrapper_attributes; ?>>
    <div class="flex flex-col md:flex-row justify-between w-full gap-10 border-b border-gray-500/30 pb-6">
        <!-- Logo and Description -->
        <div class="md:max-w-96">
            <?php if ($show_logo): ?>
                <?php if (!empty($logo['url'])): ?>
                    <img data-proto-field="logo" src="<?php echo esc_url($logo['url']); ?>" class="h-10 max-w-[160px] object-contain" alt="<?php echo esc_attr($logo['alt'] ?? 'Logo'); ?>" />
                <?php else: ?>
                    <!-- Default SVG Logo placeholder -->
                    <svg data-proto-field="logo" class="h-10 w-auto text-primary-600" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor" d="M 247.29 113.27 C 281.81 92.76 317.20 73.73 351.74 53.24 C 381.54 70.41 411.26 87.73 441.05 104.91 C 445.94 107.55 450.55 110.67 455.17 113.76 C 455.17 190.50 455.17 267.24 455.16 343.98 C 421.16 364.15 386.68 383.52 352.65 403.64 C 320.46 422.38 288.26 441.09 256.02 459.73 C 219.34 438.57 182.70 417.33 146.19 395.88 C 116.39 378.59 86.48 361.51 56.83 343.99 C 56.83 303.91 56.82 263.83 56.83 223.75 C 86.20 206.41 115.83 189.50 145.32 172.37 C 150.57 169.58 155.39 166.09 160.53 163.12 C 166.16 166.77 171.92 170.20 178.05 172.97 C 201.17 186.24 224.21 199.68 247.30 213.01 C 247.27 179.76 247.30 146.52 247.29 113.27 M 273.42 118.25 C 299.35 133.26 325.25 148.31 351.22 163.24 C 359.85 159.03 368.03 154.02 376.35 149.25 C 393.91 139.08 411.49 128.94 429.04 118.75 C 403.10 103.76 377.20 88.68 351.22 73.76 C 325.35 88.68 299.41 103.50 273.42 118.25 M 265.23 134.08 C 265.20 163.98 265.22 193.88 265.21 223.78 C 291.25 238.14 316.57 253.78 342.54 268.26 C 342.49 238.42 342.56 208.58 342.51 178.74 C 316.94 163.53 290.99 148.96 265.23 134.08 M 359.95 178.75 C 359.93 208.41 359.94 238.07 359.94 267.73 C 385.92 253.00 411.84 238.15 437.73 223.27 C 437.79 193.43 437.75 163.60 437.75 133.76 C 411.84 148.79 385.67 163.40 359.95 178.75 M 82.95 228.27 C 108.85 243.38 134.92 258.23 160.73 273.50 C 186.52 258.44 212.27 243.33 238.05 228.26 C 212.33 213.18 186.19 198.81 160.76 183.25 C 135.01 198.56 108.86 213.21 82.95 228.27 M 234.70 250.70 C 215.86 261.54 197.02 272.38 178.18 283.24 C 204.09 298.29 230.24 312.94 256.00 328.23 C 281.73 313.36 307.55 298.63 333.27 283.74 C 307.44 268.48 281.22 253.89 255.47 238.51 C 248.74 242.90 241.61 246.61 234.70 250.70 M 74.24 243.76 C 74.25 273.60 74.21 303.43 74.26 333.27 C 100.19 348.25 126.10 363.27 152.04 378.23 C 152.08 348.41 152.05 318.59 152.05 288.77 C 126.09 273.81 100.18 258.76 74.24 243.76 M 359.95 288.74 C 359.93 318.56 359.92 348.38 359.95 378.20 C 385.91 363.28 411.80 348.23 437.74 333.27 C 437.78 303.60 437.75 273.93 437.75 244.26 C 411.82 259.09 385.86 273.87 359.95 288.74 M 264.74 343.44 C 264.66 373.37 264.73 403.31 264.71 433.24 C 290.68 418.40 316.78 403.76 342.51 388.52 C 342.54 358.51 342.52 328.51 342.53 298.51 C 316.58 313.46 290.73 328.56 264.74 343.44 M 169.48 388.51 C 195.07 403.78 221.04 418.43 246.77 433.46 C 246.79 403.65 246.77 373.84 246.79 344.03 C 220.99 329.11 195.22 314.13 169.43 299.19 C 169.53 328.96 169.43 358.74 169.48 388.51 Z" />
                    </svg>
                <?php endif; ?>
            <?php endif; ?>
            <p class="mt-6 text-sm" data-proto-field="description">
                <?php echo esc_html($description); ?>
            </p>
        </div>

        <!-- Navigation Columns -->
        <div class="flex-1 flex items-start md:justify-end gap-20">
            <?php if ($show_column1): ?>
            <!-- Column 1 - Navigation Links -->
            <div>
                <h2 class="font-semibold mb-5 text-gray-800" data-proto-field="column1Title"><?php echo esc_html($column1_title); ?></h2>
                <ul class="text-sm space-y-2" data-proto-repeater="column1Links">
                    <?php foreach ($column1_links as $link):
                        $label = $link['label'] ?? 'Link';
                        $url = $link['url'] ?? '#';
                    ?>
                    <li data-proto-repeater-item>
                        <a href="<?php echo esc_url($url); ?>" class="hover:text-primary-600 transition-colors">
                            <span data-proto-field="label"><?php echo esc_html($label); ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($show_column2): ?>
            <!-- Column 2 - Contact Info -->
            <div>
                <h2 class="font-semibold mb-5 text-gray-800" data-proto-field="column2Title"><?php echo esc_html($column2_title); ?></h2>
                <div class="text-sm space-y-2">
                    <p data-proto-field="phone"><?php echo esc_html($phone); ?></p>
                    <p data-proto-field="email"><?php echo esc_html($email); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Copyright -->
    <p class="pt-4 text-center text-xs md:text-sm pb-5">
        <span data-proto-field="copyrightText"><?php echo esc_html($copyright_text); ?></span>
        &copy;
        <a href="<?php echo esc_url($copyright_link['url'] ?? '#'); ?>"
           class="text-primary-600 hover:text-primary-700 transition-colors"
           data-proto-field="copyrightLink"
           <?php echo !empty($copyright_link['target']) ? 'target="' . esc_attr($copyright_link['target']) . '"' : ''; ?>>
            <?php echo esc_html($copyright_link['text'] ?? 'Your Company'); ?>
        </a>.
        All Rights Reserved.
    </p>
</footer>
