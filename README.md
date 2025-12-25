<h1><img src="proto-icon-black.svg" width="32" height="32" alt="Proto-Blocks Logo" style="vertical-align: middle; margin-right: 8px;">Proto-Blocks</h1>

A next-generation WordPress plugin that enables developers to create Gutenberg blocks using PHP/HTML templates instead of React.

<table>
  <tr>
    <td><img src="examples/example-components.png" alt="Proto-Blocks Example Components" width="100%"></td>
    <td><img src="examples/admin.png" alt="Proto-Blocks Admin Panel" width="100%"></td>
  </tr>
</table>

## Features

- **Single Source of Truth**: Define blocks using a single `block.json` file
- **Template Caching**: Compiled templates are cached for optimal performance
- **Extensible Field Types**: Plugin architecture for custom field types
- **Enhanced Repeater**: Drag-drop reordering, collapse/expand, duplicate, min/max limits
- **Interactivity API Support**: Full support for WordPress Interactivity API directives
- **WP-CLI Commands**: Scaffold, validate, and manage blocks from the command line
- **TypeScript Editor**: Type-safe editor components for better developer experience
- **Tailwind CSS Support**: Build blocks with Tailwind CSS using themed colors
- **Setup Wizard**: Guided first-time configuration for quick setup

## Requirements

- WordPress 6.3+
- PHP 8.0+

## Installation

1. Download or clone this repository to your `wp-content/plugins` directory
2. Run `npm install` to install dependencies
3. Run `npm run build` to build the JavaScript assets
4. Activate the plugin in WordPress admin

## Creating Your First Block

This guide walks you through creating a Proto Block from scratch. Proto Blocks use PHP templates instead of React, making them accessible to developers familiar with WordPress theme development.

### Prerequisites

Before creating blocks, ensure you have:
- Proto-Blocks plugin activated
- A theme with write permissions
- Basic understanding of PHP and HTML

> **💡 Tip:** Run the Setup Wizard first (Proto-Blocks menu) to install demo blocks. These serve as excellent references while building your own.

### 1. Create the Block Directory

Create a `proto-blocks` directory in your active theme:

```
your-theme/
└── proto-blocks/
    └── my-card/
        ├── block.json       ← Required: Block configuration
        ├── template.php     ← Required: PHP template
        ├── style.css        ← Optional: Block styles
        ├── view.js          ← Optional: Frontend JavaScript
        └── preview.png      ← Optional: Block preview image
```

> **⚠️ Warning:** The block folder name must match the block name in `block.json` (e.g., folder `my-card` → name `proto-blocks/my-card`).

> **💡 Tip:** Use lowercase letters and hyphens for folder names. Avoid spaces and special characters.

### 2. Define the Block Schema (block.json)

The `block.json` file is the heart of your block. It defines the block's identity, fields, and controls.

#### Minimal Example (Vanilla CSS)

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "proto-blocks/my-card",
    "title": "My Card",
    "category": "proto-blocks",
    "icon": "admin-post",
    "description": "A simple card block.",
    "keywords": ["card", "box", "content"],
    "protoBlocks": {
        "version": "1.0",
        "template": "template.php",
        "fields": {
            "title": {
                "type": "text",
                "tagName": "h3"
            },
            "content": {
                "type": "wysiwyg"
            }
        }
    }
}
```

#### Complete Example with All Options

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "proto-blocks/my-card",
    "title": "My Card",
    "description": "A versatile card with image, title, and content.",
    "category": "proto-blocks",
    "icon": "admin-post",
    "keywords": ["card", "box", "feature"],
    "supports": {
        "html": false,
        "anchor": true,
        "customClassName": true,
        "align": ["wide", "full"],
        "color": {
            "background": true,
            "text": true
        },
        "spacing": {
            "padding": true,
            "margin": true
        }
    },
    "protoBlocks": {
        "version": "1.0",
        "useTailwind": false,
        "template": "template.php",
        "fields": {
            "image": {
                "type": "image",
                "sizes": ["medium", "large"]
            },
            "title": {
                "type": "text",
                "tagName": "h3"
            },
            "content": {
                "type": "wysiwyg"
            },
            "link": {
                "type": "link",
                "tagName": "a"
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
            },
            "showLink": {
                "type": "toggle",
                "label": "Show Call to Action",
                "default": true
            }
        }
    }
}
```

#### Tailwind CSS Example

To create a Tailwind-styled block, set `useTailwind: true`:

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "proto-blocks/tw-card",
    "title": "Tailwind Card",
    "category": "proto-blocks",
    "icon": "admin-post",
    "protoBlocks": {
        "version": "1.0",
        "useTailwind": true,
        "template": "template.php",
        "fields": {
            "title": {
                "type": "text",
                "tagName": "h3"
            }
        }
    }
}
```

> **⚠️ Important:** Tailwind blocks require Tailwind CSS to be enabled in Proto-Blocks > Tailwind Settings.

#### block.json Property Reference

| Property | Required | Description |
|----------|----------|-------------|
| `$schema` | No | WordPress block schema URL for IDE autocompletion |
| `apiVersion` | Yes | WordPress block API version (use `3`) |
| `name` | Yes | Unique block identifier (`namespace/block-name`) |
| `title` | Yes | Human-readable block name shown in inserter |
| `category` | Yes | Block category (use `proto-blocks` or custom) |
| `icon` | No | Dashicon name (without `dashicons-` prefix) |
| `description` | No | Block description shown in inserter |
| `keywords` | No | Search keywords array |
| `supports` | No | WordPress block supports configuration |
| `protoBlocks` | Yes | Proto-Blocks specific configuration |

#### protoBlocks Property Reference

| Property | Required | Description |
|----------|----------|-------------|
| `version` | Yes | Proto-Blocks schema version (use `"1.0"`) |
| `template` | Yes | PHP template filename |
| `useTailwind` | No | Enable Tailwind CSS support (`true`/`false`) |
| `fields` | No | Editable content fields (see Field Types) |
| `controls` | No | Inspector panel controls (see Control Types) |
| `isExample` | No | Mark as example block (for demo blocks) |

### 3. Create the Template (template.php)

The template renders your block's HTML. It receives three variables:

```php
<?php
/**
 * Block Template: My Card
 *
 * @var array    $attributes Block attributes (field and control values)
 * @var string   $content    Inner blocks content (if using inner blocks)
 * @var WP_Block $block      Block instance (null in editor preview)
 */

// Always provide default values with null coalescing
$layout = $attributes['layout'] ?? 'vertical';
$show_link = $attributes['showLink'] ?? true;
$title = $attributes['title'] ?? '';
$content = $attributes['content'] ?? '';
$image = $attributes['image'] ?? [];
$link = $attributes['link'] ?? [];

// Detect editor preview mode
$is_preview = !isset($block) || $block === null;

// Build CSS classes
$classes = [
    'wp-block-proto-blocks-my-card',
    'my-card',
    'my-card--' . esc_attr($layout),
];

// Use WordPress function for wrapper attributes
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => implode(' ', $classes),
]);
?>

<article <?php echo $wrapper_attributes; ?>>
    <?php if (!empty($image['url']) || $is_preview): ?>
        <figure class="my-card__image" data-proto-field="image">
            <?php if (!empty($image['url'])): ?>
                <img
                    src="<?php echo esc_url($image['url']); ?>"
                    alt="<?php echo esc_attr($image['alt'] ?? ''); ?>"
                    loading="lazy"
                />
            <?php endif; ?>
        </figure>
    <?php endif; ?>

    <div class="my-card__content">
        <h3 class="my-card__title" data-proto-field="title">
            <?php echo esc_html($title); ?>
        </h3>

        <div class="my-card__body" data-proto-field="content">
            <?php echo wp_kses_post($content); ?>
        </div>

        <?php if ($show_link): ?>
            <a
                href="<?php echo esc_url($link['url'] ?? '#'); ?>"
                class="my-card__link"
                data-proto-field="link"
                <?php echo !empty($link['target']) ? 'target="' . esc_attr($link['target']) . '"' : ''; ?>
            >
                <?php echo esc_html($link['text'] ?? 'Learn More'); ?>
            </a>
        <?php endif; ?>
    </div>
</article>
```

#### Template Best Practices

> **✅ Do:** Always use `data-proto-field` on elements that should be editable in the block editor.

> **✅ Do:** Always provide default values using null coalescing (`??`).

> **✅ Do:** Always escape output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`).

> **✅ Do:** Use `get_block_wrapper_attributes()` for the root element.

> **❌ Don't:** Hide elements completely when empty - keep them for editor editing:

```php
<!-- ❌ BAD: Can't edit when empty -->
<?php if (!empty($title)): ?>
    <h3><?php echo esc_html($title); ?></h3>
<?php endif; ?>

<!-- ✅ GOOD: Always shows editable element -->
<h3 data-proto-field="title"><?php echo esc_html($title); ?></h3>
```

> **❌ Don't:** Forget to handle preview mode for repeaters:

```php
<!-- ✅ GOOD: Provide default items for preview -->
<?php
if (empty($items)) {
    if ($is_preview) {
        $items = [
            ['title' => 'Item 1', 'content' => 'Click to edit...'],
            ['title' => 'Item 2', 'content' => 'Add more items...'],
        ];
    } else {
        return; // Don't render empty block on frontend
    }
}
?>
```

### 4. Add Styles (style.css) - Optional

Create a `style.css` file for your block styles:

```css
/* Block: My Card */
.my-card {
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.my-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.my-card--horizontal {
    flex-direction: row;
}

.my-card__image {
    margin: 0;
}

.my-card__image img {
    width: 100%;
    height: auto;
    display: block;
}

.my-card__content {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.my-card__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #1a1a1a;
}

.my-card__body {
    color: #666;
    line-height: 1.6;
}

.my-card__link {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: #0073aa;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    font-weight: 500;
    margin-top: auto;
    align-self: flex-start;
}

.my-card__link:hover {
    background: #005a87;
}
```

> **💡 Tip:** Proto-Blocks automatically enqueues `style.css` files from block directories.

### 5. Add Preview Image (preview.png) - Optional

Add a `preview.png` (400px wide recommended) to show in the block inserter instead of "No preview available".

### Quick Start Checklist

- [ ] Created `proto-blocks/` folder in theme
- [ ] Created block subfolder with lowercase name
- [ ] Created `block.json` with required properties
- [ ] Created `template.php` with `data-proto-field` attributes
- [ ] Added default values for all attributes
- [ ] Escaped all output properly
- [ ] Tested block in editor and frontend

### Common Mistakes to Avoid

| Mistake | Solution |
|---------|----------|
| Block not appearing | Check `block.json` syntax with JSON validator |
| Fields not editable | Ensure `data-proto-field="fieldName"` matches field key |
| Repeater not working | Use both `data-proto-repeater` and `data-proto-repeater-item` |
| Styles not loading | Check file is named `style.css` in block folder |
| Tailwind not working | Enable Tailwind in Proto-Blocks > Tailwind Settings |
| Preview shows error | Check PHP syntax, enable `WP_DEBUG` |

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

## Block Preview Screenshots

Proto-Blocks supports custom preview screenshots that appear in the block inserter. Instead of showing "No preview available" or a rendered preview, you can provide a static image that shows exactly how your block looks.

### Adding a Preview Image

Simply add an image file named `preview` to your block folder:

```
your-block/
├── block.json
├── template.php
├── style.css
└── preview.png    ← Your preview screenshot
```

### Supported Formats

- `preview.png` (recommended)
- `preview.jpg`
- `preview.jpeg`
- `preview.webp`

### Recommended Dimensions

- **Width**: 400px (matches the inserter preview viewport)
- **Height**: Proportional to your block's typical appearance
- **Format**: PNG for crisp text and UI elements, JPG/WebP for photographic content

### Example

```
examples/
└── accordion/
    ├── block.json
    ├── template.php
    ├── style.css
    ├── view.js
    └── preview.png    ← Shows accordion in expanded state
```

When users hover over your block in the inserter, they'll see your custom preview image instead of the default "No preview available" message.

### Benefits

- **Visual clarity**: Users immediately see what the block looks like
- **Faster inserter**: No need to render a live preview
- **Design control**: Show the block in its best state (expanded, populated, styled)

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

## Setup Wizard

Proto-Blocks includes a guided setup wizard that appears on first activation to help you configure the plugin quickly.

### Wizard Steps

1. **Welcome** - Introduction to Proto-Blocks
2. **Configure** - Choose component style (Vanilla CSS or Tailwind CSS) and set custom category name
3. **Install** - Automatically installs demo blocks based on your style preference
4. **Complete** - Summary of configuration and next steps

### Re-running the Wizard

To re-run the setup wizard:
1. Go to **Proto-Blocks > System Status** in WordPress admin
2. Click **"Run Setup Wizard Again"**

Or delete the option programmatically:
```php
delete_option('proto_blocks_wizard_completed');
```

## Tailwind CSS Support

Proto-Blocks includes built-in support for Tailwind CSS, allowing you to create blocks styled entirely with utility classes.

### Enabling Tailwind for a Block

Add `useTailwind: true` to your block's `protoBlocks` configuration:

```json
{
    "protoBlocks": {
        "version": "1.0",
        "useTailwind": true,
        "template": "template.php",
        "fields": { ... }
    }
}
```

### Themed Colors

Tailwind blocks have access to themed colors that can be customized in the admin:

| Color | CSS Variable | Default | Usage |
|-------|--------------|---------|-------|
| `primary-50` to `primary-950` | `--tw-color-primary-*` | Blue shades | Brand color, buttons, links |
| `secondary-50` to `secondary-950` | `--tw-color-secondary-*` | Teal shades | Accent elements |
| `accent-50` to `accent-950` | `--tw-color-accent-*` | Red shades | Highlights, alerts |

Use these in your templates:
```html
<button class="bg-primary-600 hover:bg-primary-700 text-white">
    Click me
</button>
<span class="text-secondary-500">Secondary text</span>
```

### Tailwind Settings

Configure Tailwind CSS options in **Proto-Blocks > Tailwind Settings**:

- **Enable/Disable Tailwind** - Toggle Tailwind CSS compilation
- **Primary Color** - Set the primary brand color
- **Secondary Color** - Set the secondary accent color
- **Accent Color** - Set the highlight/alert color
- **Compile on Reload** - Enable development mode for automatic recompilation
- **Disable Global Styles** - Remove WordPress default styles for cleaner output

## Block Category

Proto-Blocks registers a custom block category that appears at the **top** of the block inserter for easy access to your custom blocks.

### Default Configuration

- **Title**: "Proto Blocks"
- **Slug**: `proto-blocks`
- **Icon**: `layout` (dashicon)
- **Position**: First in the block inserter

### Customizing the Category

You can customize the category name directly in the WordPress admin:

1. Go to **Proto-Blocks > System Status**
2. Find the **"General Settings"** section
3. Enter your custom category name
4. Click **"Save Category Name"**

Alternatively, use WordPress filters in your theme's `functions.php` or a custom plugin:

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

## Demo Blocks

Proto-Blocks includes two sets of demo blocks to showcase all available capabilities. These can be installed to your theme via the admin panel or the setup wizard.

### Vanilla CSS Blocks

Classic CSS-styled demo blocks (6 blocks):

| Block | Purpose | Key Features Demonstrated |
|-------|---------|--------------------------|
| **Card** | Versatile content card | Image field, WYSIWYG, Link field, Conditional controls |
| **Testimonial** | Customer testimonial | Range control (star rating), Toggle controls, Image sizes |
| **Accordion** | Collapsible sections | Repeater field, Interactivity API, Drag-drop reordering |
| **Hero** | Full-width hero section | Inner Blocks, Color control, Color-palette, Radio control, Number control, Image control |
| **Stats** | Statistics counter | Number control, Simple repeater, Range control |
| **CTA** | Call to action | Textarea control, Checkbox control, Radio control |

### Tailwind CSS Blocks

Modern Tailwind-styled demo blocks (3 blocks):

| Block | Purpose | Key Features Demonstrated |
|-------|---------|--------------------------|
| **Header Navigation** | Responsive header | Repeater for nav items, Logo image, Link field, Mobile menu |
| **Tailwind Hero** | Dark gradient hero | Badge link, Dual CTAs, Toggle controls, Gradient backgrounds |
| **Tailwind Footer** | Responsive footer | Logo, Description, Repeater for links, Contact info, Copyright |

#### Header Navigation Block

A responsive header with logo, navigation menu, and call-to-action button.

**Fields:**
- `logo` (Image) - Site logo
- `siteTitle` (Text) - Site name
- `navItems` (Repeater) - Navigation links (1-8 items)
  - `label` (Text) - Link text
  - `url` (Text) - Link URL
- `ctaButton` (Link) - Call-to-action button

**Controls:**
- `showCta` (Toggle) - Show/hide CTA button
- `fixedPosition` (Toggle) - Keep header fixed at top

---

#### Tailwind Hero Block

A dark hero section with gradient backgrounds, announcement badge, and dual CTAs.

**Fields:**
- `badgeText` (Text) - Announcement badge text
- `badgeLink` (Link) - Badge link
- `heading` (Text) - Main heading (h1)
- `description` (Text) - Supporting text
- `primaryButton` (Link) - Primary CTA button
- `secondaryLink` (Link) - Secondary text link

**Controls:**
- `showBadge` (Toggle) - Show/hide announcement badge
- `showSecondaryLink` (Toggle) - Show/hide secondary link

---

#### Tailwind Footer Block

A responsive footer with logo, description, navigation columns, contact info, and copyright.

**Fields:**
- `logo` (Image) - Footer logo
- `description` (Text) - Company description
- `column1Title` (Text) - Navigation column title
- `column1Links` (Repeater) - Navigation links (1-10 items)
  - `label` (Text) - Link text
  - `url` (Text) - Link URL
- `column2Title` (Text) - Contact column title
- `phone` (Text) - Phone number
- `email` (Text) - Email address
- `copyrightText` (Text) - Copyright text
- `copyrightLink` (Link) - Company link

**Controls:**
- `showLogo` (Toggle) - Show/hide logo
- `showColumn1` (Toggle) - Show/hide navigation column
- `showColumn2` (Toggle) - Show/hide contact column

---

### Vanilla CSS Block Details

#### Card Block

![Card Block Preview](examples/card/preview.png)

A versatile card with image, title, content, and call-to-action link.

**Fields:**
- `image` (Image) - Featured image with size options
- `title` (Text) - Card heading
- `content` (WYSIWYG) - Rich text body content
- `link` (Link) - Call-to-action button

**Controls:**
- `layout` (Select) - Vertical, Horizontal, or Overlay
- `imagePosition` (Select) - Top, Bottom, Left, Right (conditional - only shows for certain layouts)
- `showLink` (Toggle) - Show/hide call-to-action

**Demonstrates:**
- Image field with multiple size options
- Link field with popover editor
- WYSIWYG rich text editing
- Conditional control visibility
- WordPress block supports (colors, spacing)

---

#### Testimonial Block

![Testimonial Block Preview](examples/testimonial/preview.png)

Customer testimonial with rating and author information.

**Fields:**
- `quote` (WYSIWYG) - Testimonial text
- `authorName` (Text) - Customer name
- `authorTitle` (Text) - Customer title/role
- `authorImage` (Image) - Customer photo

**Controls:**
- `style` (Select) - Default, Bordered, or Filled
- `showAvatar` (Toggle) - Show/hide author photo
- `rating` (Range) - 0-5 star rating
- `showRating` (Toggle) - Show/hide star rating

**Demonstrates:**
- Range control for numeric values
- Multiple toggle controls
- Image field with thumbnail size
- Style variations via select control

---

#### Accordion Block

![Accordion Block Preview](examples/accordion/preview.png)

Collapsible content sections with expand/collapse functionality.

**Fields:**
- `items` (Repeater) - List of accordion sections
  - `title` (Text) - Section header
  - `content` (WYSIWYG) - Section content

**Controls:**
- `allowMultiple` (Toggle) - Allow multiple sections open simultaneously
- `firstOpen` (Toggle) - Open first item by default
- `iconPosition` (Select) - Icon position (left/right)

**Demonstrates:**
- **Repeater field** with nested fields
- Drag-drop reordering
- Collapsible repeater items
- Item duplication
- Min/max item limits
- **WordPress Interactivity API** for frontend behavior
- Data binding with `data-wp-*` directives

---

#### Hero Section Block

![Hero Section Block Preview](examples/hero/preview.png)

Full-width hero section with background image, customizable colors, and nested content.

**Fields:**
- `title` (Text) - Main heading (h1)
- `subtitle` (Text) - Supporting text
- `innerContent` (Inner Blocks) - Nested WordPress blocks

**Controls:**
- `backgroundImage` (Image) - Background image selector
- `backgroundColor` (Color) - Overlay color picker
- `overlayOpacity` (Range) - Overlay transparency
- `textColor` (Color-palette) - Text color from WordPress palette
- `contentAlignment` (Radio) - Left, Center, Right alignment
- `minHeight` (Number) - Minimum section height in vh units
- `verticalAlignment` (Select) - Top, Center, Bottom

**Demonstrates:**
- **Inner Blocks field** for nested block content
- **Color control** with full color picker
- **Color-palette control** using WordPress colors
- **Radio control** for alignment options
- **Number control** for numeric input
- **Image control** in inspector panel
- Template with block appender

---

#### Stats Counter Block

![Stats Counter Block Preview](examples/stats/preview.png)

Display statistics and numbers with labels in a grid layout.

**Fields:**
- `stats` (Repeater) - List of statistics
  - `number` (Text) - The statistic value
  - `prefix` (Text) - Optional prefix (e.g., "$")
  - `suffix` (Text) - Optional suffix (e.g., "%", "K", "M")
  - `label` (Text) - Description label

**Controls:**
- `columns` (Number) - Grid columns (1-6)
- `style` (Select) - Default, Boxed, Bordered, Minimal
- `numberSize` (Range) - Font size for numbers
- `showDividers` (Toggle) - Show dividers between items

**Demonstrates:**
- **Number control** for column count
- Simple repeater with multiple text fields
- CSS custom properties for dynamic styling
- Responsive grid layout

---

#### Call to Action Block

![Call to Action Block Preview](examples/cta/preview.png)

Prominent call-to-action section with customizable styling.

**Fields:**
- `title` (Text) - CTA heading
- `link` (Link) - Action button

**Controls:**
- `description` (Textarea) - Multi-line description text
- `backgroundColor` (Color-palette) - Background color
- `textColor` (Color-palette) - Text color
- `buttonStyle` (Radio) - Primary, Secondary, Outline
- `layout` (Select) - Centered, Inline, Stacked
- `showIcon` (Checkbox) - Show arrow icon on button
- `fullWidth` (Checkbox) - Full-width button (conditional)

**Demonstrates:**
- **Textarea control** for multi-line input
- **Checkbox control** for boolean options
- Multiple color-palette controls
- Conditional control visibility
- SVG icon integration

### Installing Demo Blocks

1. Go to **Proto-Blocks** in the WordPress admin menu
2. Click **"Install Demo Blocks to Theme"**
3. Demo blocks are copied to your theme's `proto-blocks/` directory
4. Start customizing or use them as learning references

You can also use the **Setup Wizard** to install demo blocks during initial configuration.

### Removing Demo Blocks

When you're ready to start fresh:

1. Go to **Proto-Blocks** in the WordPress admin menu
2. Click **"Remove Demo Blocks"**
3. All demo blocks are removed from your theme
4. Your custom blocks are not affected

---

### Capability Coverage Matrix

#### Vanilla CSS Blocks

| Capability | Card | Testimonial | Accordion | Hero | Stats | CTA |
|------------|:----:|:-----------:|:---------:|:----:|:-----:|:---:|
| **Fields** |
| Text | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Image | ✓ | ✓ | | | | |
| Link | ✓ | | | | | ✓ |
| WYSIWYG | ✓ | ✓ | ✓ | | | |
| Repeater | | | ✓ | | ✓ | |
| Inner Blocks | | | | ✓ | | |
| **Controls** |
| Select | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Toggle | ✓ | ✓ | ✓ | | ✓ | |
| Range | | ✓ | | ✓ | ✓ | |
| Number | | | | ✓ | ✓ | |
| Color | | | | ✓ | | |
| Color-palette | | | | ✓ | | ✓ |
| Radio | | | | ✓ | | ✓ |
| Textarea | | | | | | ✓ |
| Checkbox | | | | | | ✓ |
| Image (inspector) | | | | ✓ | | |
| **Features** |
| Conditional controls | ✓ | | | | | ✓ |
| Interactivity API | | | ✓ | | | |
| Block supports | ✓ | | | | ✓ | |

#### Tailwind CSS Blocks

| Capability | Header | Hero | Footer |
|------------|:------:|:----:|:------:|
| **Fields** |
| Text | ✓ | ✓ | ✓ |
| Image | ✓ | | ✓ |
| Link | ✓ | ✓ | ✓ |
| Repeater | ✓ | | ✓ |
| **Controls** |
| Toggle | ✓ | ✓ | ✓ |
| **Features** |
| Tailwind CSS | ✓ | ✓ | ✓ |
| Themed Colors | ✓ | ✓ | ✓ |
| Responsive Design | ✓ | ✓ | ✓ |

> **Note:** The demo blocks use the WordPress Interactivity API for their frontend JavaScript, but this is purely for demonstration purposes. You can use plain JavaScript, ES modules, jQuery, or any other approach you prefer. See the [Frontend JavaScript](#frontend-javascript-interactivity) section for alternatives.

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
