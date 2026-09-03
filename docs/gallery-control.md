# The `gallery` Control

`gallery` stores an **ordered list of images**. It is the multiple-value
counterpart to the [`image` control](../README.md#control-types): same stored
shape per item, several of them, arranged by the author.

```json
"images": {
  "type": "gallery",
  "label": "Images"
}
```

No other configuration. There is nothing to configure — the media library is
the source, and the order is whatever the author dragged into place.

## The stored value

An array of objects, in the author's order:

```php
$images = $attributes['images'] ?? [];
// [
//   ['id' => 412, 'url' => 'https://…/oven-large.jpg', 'alt' => 'A rack oven'],
//   ['id' => 415, 'url' => 'https://…/mixer-large.jpg', 'alt' => ''],
// ]
```

Each item is the same `{ id, url, alt }` shape the single `image` control
stores, so one item of a gallery reads exactly like an image control's value.

`url` is a **generated size** (`large`, falling back through `medium_large`
and `medium` to the original) rather than the full-size file. The inspector
renders these as small thumbnails, and a page of full-size originals is a
slow sidebar.

## Reading it in a template

```php
<?php foreach (($attributes['images'] ?? []) as $image) : ?>
    <li>
        <?php
        // From the attachment id, so WordPress emits srcset. Falling back to
        // the stored url covers an attachment deleted since it was chosen.
        if (! empty($image['id'])) {
            echo wp_get_attachment_image((int) $image['id'], 'large', false, [
                'alt' => esc_attr($image['alt'] ?? ''),
            ]);
        } elseif (! empty($image['url'])) {
            ?>
            <img src="<?php echo esc_url($image['url']); ?>"
                 alt="<?php echo esc_attr($image['alt'] ?? ''); ?>" />
            <?php
        }
        ?>
    </li>
<?php endforeach; ?>
```

There is no `data-proto-*` binding. The list lives in the inspector, so the
markup carries no editing furniture — see below.

## Why not a repeater of image fields?

A repeater can hold a list of images, and for an editable list of *content* —
cards with headings and links — it remains the right tool, because each row's
text is edited in place where the author can see it.

For a list that is purely images, the repeater costs you the editor preview:

- **A repeater renders its own chrome inside the block's markup.** Add, remove
  and drag affordances are injected among the items, so the canvas shows
  furniture the front end does not have.
- **That breaks any layout computed from the item count or their positions.**
  A carousel, a diagonal band, a masonry wall — anything where item *n*'s
  placement depends on the others — cannot be judged in the editor, because
  what is on the canvas is not what will render.
- **The inspector has no such problem.** The control sits in the sidebar; the
  markup the editor renders is byte-for-byte the markup the front end renders.

So: **use a `gallery` control when the images are the layout, and a repeater
when each row is content the author should edit in place.**

## Authoring behaviour

- **Selection is core's media modal in gallery mode** — the same interaction
  as core's Gallery block, including reordering inside the modal.
- **Reopening edits rather than restarts.** The modal opens on the current
  selection, so nothing is lost by opening it again.
- **A sortable strip in the sidebar** offers reordering without the modal.
  Drag a thumbnail, or focus one and use the arrow keys — dnd-kit's keyboard
  sensor is wired up, so the control is fully operable without a pointer.
- **Removing** is the `×` on each thumbnail.
- **Duplicates collapse.** The gallery frame does not offer the same
  attachment twice; if a malformed value contains one, only the first survives
  — two items with one identity would be indistinguishable to drag-and-drop.
- **An attachment still uploading is skipped** until it has a URL, rather than
  being stored as a permanently broken image.

## Validation

`gallery` is a recognised control type, so `wp proto-blocks validate` accepts
it. It takes no `options`, no `optionsSource`, and no `min`/`max`.
