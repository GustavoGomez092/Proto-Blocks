# Proto-Blocks

A next-generation WordPress plugin that enables developers to create Gutenberg blocks using PHP/HTML templates instead of React.

## Features

- **Single Source of Truth**: Define blocks using a single `block.json` file
- **Template Caching**: Compiled templates are cached for optimal performance
- **Extensible Field Types**: Plugin architecture for custom field types
- **Enhanced Repeater**: Drag-drop reordering, collapse/expand, duplicate, min/max limits
- **Interactivity API Support**: Full support for WordPress Interactivity API directives
- **WP-CLI Commands**: Scaffold, validate, and manage blocks from the command line
- **TypeScript Editor**: Type-safe editor components for better developer experience

## Requirements

- WordPress 6.3+
- PHP 8.0+

## Installation

1. Download or clone this repository to your `wp-content/plugins` directory
2. Run `npm install` to install dependencies
3. Run `npm run build` to build the JavaScript assets
4. Activate the plugin in WordPress admin

## Creating Your First Block

### 1. Create the Block Directory

Create a `proto-blocks` directory in your theme:

```
your-theme/
└── proto-blocks/
    └── card/
        ├── block.json
        ├── template.php
        └── style.css
```

### 2. Define the Block Schema (block.json)

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "proto-blocks/card",
    "title": "Card",
    "category": "proto-blocks",
    "icon": "admin-post",
    "protoBlocks": {
        "version": "1.0",
        "template": "template.php",
        "fields": {
            "title": {
                "type": "text",
                "tagName": "h2"
            },
            "content": {
                "type": "wysiwyg"
            },
            "image": {
                "type": "image"
            }
        },
        "controls": {
            "layout": {
                "type": "select",
                "label": "Layout",
                "default": "vertical",
                "options": [
                    { "key": "vertical", "label": "Vertical" },
                    { "key": "horizontal", "label": "Horizontal" }
                ]
            }
        }
    }
}
```

### 3. Create the Template (template.php)

```php
<?php
/**
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks content.
 * @var WP_Block $block      Block instance.
 */

$layout = $attributes['layout'] ?? 'vertical';
?>

<div class="my-card my-card--<?php echo esc_attr($layout); ?>">
    <?php if (!empty($attributes['image']['url'])) : ?>
        <figure data-proto-field="image">
            <img src="<?php echo esc_url($attributes['image']['url']); ?>" alt="" />
        </figure>
    <?php endif; ?>

    <h2 data-proto-field="title"><?php echo esc_html($attributes['title'] ?? ''); ?></h2>

    <div data-proto-field="content">
        <?php echo wp_kses_post($attributes['content'] ?? ''); ?>
    </div>
</div>
```

## Field Types

### Text Field

```json
{
    "title": {
        "type": "text",
        "tagName": "h2"
    }
}
```

### Image Field

```json
{
    "image": {
        "type": "image",
        "sizes": ["medium", "large"]
    }
}
```

### Link Field

```json
{
    "link": {
        "type": "link",
        "tagName": "a"
    }
}
```

### WYSIWYG Field

```json
{
    "content": {
        "type": "wysiwyg"
    }
}
```

### Repeater Field

```json
{
    "items": {
        "type": "repeater",
        "min": 1,
        "max": 10,
        "itemLabel": "title",
        "collapsible": true,
        "fields": {
            "title": { "type": "text" },
            "content": { "type": "wysiwyg" }
        }
    }
}
```

### Inner Blocks Field

```json
{
    "innerContent": {
        "type": "inner-blocks",
        "allowedBlocks": ["core/paragraph", "core/heading"],
        "template": [["core/paragraph", {}]]
    }
}
```

## Control Types

- `text` - Text input
- `textarea` - Multi-line text input
- `select` - Dropdown selection
- `toggle` - Boolean toggle
- `checkbox` - Boolean checkbox
- `range` - Slider with min/max
- `number` - Numeric input
- `color` - Color picker
- `color-palette` - Color palette selection
- `image` - Image selection from media library
- `radio` - Radio button group

### Conditional Controls

Controls can be conditionally shown based on other control values:

```json
{
    "imagePosition": {
        "type": "select",
        "label": "Image Position",
        "options": [...],
        "conditions": {
            "visible": {
                "layout": ["horizontal"]
            }
        }
    }
}
```

## Template Markup

Proto-Blocks uses special `data-proto-*` attributes to make template elements editable in the WordPress block editor.

### Basic Field Binding

Use `data-proto-field` to bind an element to a field:

```php
<!-- Text field - editable inline -->
<h2 data-proto-field="title"><?php echo esc_html($attributes['title'] ?? ''); ?></h2>

<!-- WYSIWYG field - rich text editing -->
<div data-proto-field="content"><?php echo wp_kses_post($attributes['content'] ?? ''); ?></div>

<!-- Image field - shows replace/remove buttons -->
<figure data-proto-field="image">
    <img src="<?php echo esc_url($attributes['image']['url'] ?? ''); ?>" alt="" />
</figure>

<!-- Link field - editable text with link popover -->
<a href="<?php echo esc_url($attributes['link']['url'] ?? '#'); ?>" data-proto-field="link">
    <?php echo esc_html($attributes['link']['text'] ?? 'Click here'); ?>
</a>
```

### Repeater Fields

Use `data-proto-repeater` for repeater containers:

```php
<ul data-proto-repeater="items">
    <?php foreach ($attributes['items'] ?? [] as $item) : ?>
        <li data-proto-repeater-item>
            <span data-proto-field="title"><?php echo esc_html($item['title'] ?? ''); ?></span>
        </li>
    <?php endforeach; ?>
</ul>
```

### Inner Blocks

Use `data-proto-inner-blocks` for nested block content:

```php
<div class="my-block__content" data-proto-inner-blocks>
    <?php echo $content; ?>
</div>
```

### Important Template Tips

1. **Always output field elements** - Even when empty, output the element with `data-proto-field` so it's editable:

```php
<!-- Good: Always shows editable element -->
<h2 data-proto-field="title"><?php echo esc_html($attributes['title'] ?? ''); ?></h2>

<!-- Bad: Element hidden when empty, can't edit -->
<?php if (!empty($attributes['title'])) : ?>
    <h2><?php echo esc_html($attributes['title']); ?></h2>
<?php endif; ?>
```

2. **Preview detection** - Check if rendering in editor preview:

```php
<?php
// $block is null during editor preview
$is_preview = !isset($block) || $block === null;

// Show placeholder content in editor
if ($is_preview && empty($attributes['title'])) {
    echo '<h2 data-proto-field="title" class="placeholder">Click to add title...</h2>';
}
?>
```

## Frontend JavaScript (Interactivity)

Proto-Blocks supports multiple approaches for adding interactivity to your blocks on the frontend. The WordPress Interactivity API used in the examples is **completely optional** - you can use plain JavaScript, jQuery, or any other approach you prefer.

### Option 1: Plain JavaScript (Recommended for Simple Interactions)

Use a regular JavaScript file for straightforward interactions:

**block.json:**
```json
{
    "viewScript": "file:./view.js"
}
```

**view.js:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    // Toggle accordion items
    const triggers = document.querySelectorAll('.my-accordion__trigger');

    triggers.forEach(trigger => {
        trigger.addEventListener('click', function() {
            const item = this.closest('.my-accordion__item');
            const isOpen = item.classList.contains('is-open');

            // Close all items
            document.querySelectorAll('.my-accordion__item').forEach(i => {
                i.classList.remove('is-open');
            });

            // Open clicked item (if it wasn't already open)
            if (!isOpen) {
                item.classList.add('is-open');
            }
        });
    });
});
```

### Option 2: ES Modules

Use ES modules for better code organization:

**block.json:**
```json
{
    "viewScriptModule": "file:./view.js"
}
```

**view.js:**
```javascript
// ES module - runs after DOM is ready
const accordions = document.querySelectorAll('.my-accordion');

accordions.forEach(accordion => {
    const items = accordion.querySelectorAll('.my-accordion__item');

    items.forEach(item => {
        const trigger = item.querySelector('.my-accordion__trigger');

        trigger?.addEventListener('click', () => {
            item.classList.toggle('is-open');
        });
    });
});
```

### Option 3: WordPress Interactivity API

The Interactivity API provides declarative, reactive state management:

**block.json:**
```json
{
    "viewScriptModule": "file:./view.js",
    "supports": {
        "interactivity": true
    }
}
```

**template.php:**
```php
<?php
$context = [
    'isOpen' => false,
];
?>
<div
    data-wp-interactive="my-namespace/accordion"
    data-wp-context='<?php echo wp_json_encode($context); ?>'
>
    <button data-wp-on--click="actions.toggle">
        Toggle
    </button>
    <div data-wp-bind--hidden="!context.isOpen">
        Content here...
    </div>
</div>
```

**view.js:**
```javascript
import { store, getContext } from '@wordpress/interactivity';

store('my-namespace/accordion', {
    actions: {
        toggle() {
            const context = getContext();
            context.isOpen = !context.isOpen;
        },
    },
});
```

### When to Use Each Approach

| Approach | Best For |
|----------|----------|
| **Plain JavaScript** | Simple toggles, one-time DOM manipulation, animations |
| **ES Modules** | Better code organization, modern syntax, tree-shaking |
| **Interactivity API** | Complex state, reactive updates, multiple components sharing state |

### Notes

- The demo blocks (Card, Testimonial, Accordion) use the Interactivity API as examples, but this is **not required**
- Plain JavaScript works perfectly fine and may be simpler for basic interactions
- You can even use jQuery if it's already loaded on your site
- Mix and match approaches across different blocks as needed

## Editor Preview System

Proto-Blocks uses a server-side rendering approach for the block editor. When you edit a block:

1. The PHP template is rendered server-side via AJAX
2. The HTML is sent to the editor
3. `data-proto-field` elements are replaced with React components
4. Changes update attributes and trigger a new preview render

### Preview Refresh Triggers

The preview automatically refreshes when:
- Any **control** value changes (layout, toggles, selects, etc.)
- Initial block load

Field edits (text, images, links) update **inline** without a full preview refresh for better performance.

## Debug Mode

Enable debug mode for detailed logging:

```php
// In wp-config.php or your theme's functions.php
define('PROTO_BLOCKS_DEBUG', true);
```

Debug mode enables:
- PHP error logging for block registration and rendering
- JavaScript console logs for preview updates and attribute changes
- Detailed error messages in AJAX responses

**Note:** Disable debug mode in production for security and performance.

## WP-CLI Commands

```bash
# List all registered blocks
wp proto-blocks list

# Create a new block scaffold
wp proto-blocks create testimonial --title="Testimonial" --fields="quote:wysiwyg,author:text"

# Validate all blocks
wp proto-blocks validate

# Clear template cache
wp proto-blocks cache clear

# Show cache statistics
wp proto-blocks cache stats

# Export a block
wp proto-blocks export card --output=/path/to/export
```

## Block Category

Proto-Blocks registers a custom block category that appears at the **top** of the block inserter for easy access to your custom blocks.

### Default Configuration

- **Title**: "Proto Blocks"
- **Slug**: `proto-blocks`
- **Icon**: `layout` (dashicon)
- **Position**: First in the block inserter

### Customizing the Category

You can customize the category using WordPress filters in your theme's `functions.php` or a custom plugin:

#### Change the Category Title

```php
add_filter('proto_blocks_category_title', function($title) {
    return 'My Custom Blocks';
});
```

#### Change the Category Icon

Use any [WordPress Dashicon](https://developer.wordpress.org/resource/dashicons/) name (without the `dashicons-` prefix):

```php
add_filter('proto_blocks_category_icon', function($icon) {
    return 'star-filled';
});
```

#### Change the Category Slug

```php
add_filter('proto_blocks_category_slug', function($slug) {
    return 'my-blocks';
});
```

**Note:** If you change the slug, update your blocks' `block.json` files to use the new category:

```json
{
    "category": "my-blocks"
}
```

## Extending Proto-Blocks

### Available Hooks

Proto-Blocks provides several hooks for customization:

#### Actions

| Hook | Description | Parameters |
|------|-------------|------------|
| `proto_blocks_init` | Fires after Proto-Blocks is initialized | `$plugin` (Plugin instance) |
| `proto_blocks_registered` | Fires after all blocks are registered | `$blocks` (array of registered block names) |

#### Filters

| Filter | Description | Parameters |
|--------|-------------|------------|
| `proto_blocks_category_title` | Customize category display name | `$title` (string) |
| `proto_blocks_category_icon` | Customize category icon | `$icon` (dashicon name) |
| `proto_blocks_category_slug` | Customize category slug | `$slug` (string) |
| `proto_blocks_paths` | Add custom block discovery paths | `$paths` (array) |
| `proto_blocks_discovered` | Modify discovered blocks | `$blocks` (array) |

### Register Custom Field Type

```php
add_action('proto_blocks_init', function($plugin) {
    $plugin->getFieldRegistry()->register('datepicker', [
        'php_class' => My_Datepicker_Field::class,
        'attribute_schema' => ['type' => 'string', 'default' => ''],
    ]);
});
```

### Add Custom Block Discovery Paths

By default, Proto-Blocks looks for blocks in:
- Your active theme's `proto-blocks/` directory
- Your parent theme's `proto-blocks/` directory (if using a child theme)

Add additional paths:

```php
add_filter('proto_blocks_paths', function($paths) {
    // Add a custom directory in your theme
    $paths[] = get_template_directory() . '/custom-blocks';

    // Add blocks from another plugin
    $paths[] = WP_PLUGIN_DIR . '/my-plugin/blocks';

    return $paths;
});
```

### Modify Discovered Blocks

Filter the list of discovered blocks before registration:

```php
add_filter('proto_blocks_discovered', function($blocks) {
    // Remove a specific block
    unset($blocks['card']);

    // Add a block programmatically
    $blocks['custom'] = '/path/to/custom/block';

    return $blocks;
});
```

### Run Code After Blocks Are Registered

```php
add_action('proto_blocks_registered', function($registeredBlocks) {
    // Log registered blocks
    error_log('Proto-Blocks registered: ' . implode(', ', $registeredBlocks));

    // Perform additional setup
    // ...
});
```

## Development

```bash
# Install dependencies
npm install

# Build for production
npm run build

# Watch for changes during development
npm run start

# Format code
npm run format

# Lint code
npm run lint:js
```

## Examples

See the `examples/` directory for complete block examples:

- **Card** - A versatile card block with image, title, and content
- **Testimonial** - Customer testimonial with rating and author info
- **Accordion** - Collapsible sections with expand/collapse functionality

> **Note:** The example blocks use the WordPress Interactivity API for their frontend JavaScript, but this is purely for demonstration purposes. You can use plain JavaScript, ES modules, jQuery, or any other approach you prefer. See the [Frontend JavaScript](#frontend-javascript-interactivity) section for alternatives.

## Troubleshooting

### Blocks Not Appearing in Editor

1. **Check the browser console** for JavaScript errors
2. **Verify block.json syntax** - Use a JSON validator
3. **Check PHP error logs** - Enable `WP_DEBUG_LOG` in wp-config.php
4. **Verify file structure** - Ensure `block.json` and `template.php` are in the same directory

### Controls Not Updating Preview

1. **Attribute names are case-sensitive** - Use camelCase consistently (e.g., `imagePosition`, not `image_position`)
2. **Verify attribute exists in block.json** - Both in `protoBlocks.fields` or `protoBlocks.controls`
3. **Check template uses correct attribute name** - `$attributes['imagePosition']` must match block.json

### Fields Not Editable

1. **Missing `data-proto-field` attribute** - Add to the element in template.php
2. **Field not defined in block.json** - Add to `protoBlocks.fields`
3. **Element hidden when empty** - Always output the element, even when the value is empty

### Preview Shows Error

1. **Enable debug mode** - `define('PROTO_BLOCKS_DEBUG', true);`
2. **Check AJAX response** - Open browser DevTools > Network tab, look for `admin-ajax.php` requests
3. **Verify template.php has no PHP errors** - Check error logs

### Category Not Showing

1. **Clear browser cache** - The block inserter caches categories
2. **Check for filter conflicts** - Another plugin might be modifying `block_categories_all`
3. **Verify hook priority** - Proto-Blocks uses priority 1, ensure no plugin overrides at priority 0

## Attribute Reference

### Image Attribute Structure

```php
$attributes['image'] = [
    'id'      => 123,           // Media library ID
    'url'     => 'https://...',  // Image URL
    'alt'     => 'Alt text',     // Alt attribute
    'caption' => 'Caption',      // Optional caption
    'size'    => 'large',        // Image size name
];
```

### Link Attribute Structure

```php
$attributes['link'] = [
    'url'    => 'https://...',   // Link URL
    'text'   => 'Click here',    // Link text
    'target' => '_blank',        // Optional: '_blank' for new tab
    'rel'    => 'noopener',      // Optional: rel attribute
];
```

### Repeater Attribute Structure

```php
$attributes['items'] = [
    [
        'id'      => 'abc123',   // Unique item ID (auto-generated)
        'title'   => 'Item 1',   // Field values
        'content' => '...',
    ],
    [
        'id'      => 'def456',
        'title'   => 'Item 2',
        'content' => '...',
    ],
];
```

## License

GPL-2.0-or-later
