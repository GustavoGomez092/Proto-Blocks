# File control

Picks any attachment from the media library.

`image` and `video` filter the library to their own kind, which is right for a
photograph or a clip and leaves no way to choose anything else: a CSV, a PDF, a
font, a caption track, a spreadsheet a block reads at render time.

## Shape

```json
{
  "protoBlocks": {
    "controls": {
      "data": {
        "type": "file",
        "label": "Data file",
        "allowedTypes": ["text/csv", "text/plain"],
        "help": "Used instead of the pasted rows when set."
      }
    }
  }
}
```

| Key | Meaning |
|---|---|
| `allowedTypes` | Media types to offer, as MIME types (`text/csv`) or top-level kinds (`application`). Omit to offer everything. |

WordPress decides what may be *uploaded*; `allowedTypes` only filters what the
picker shows. CSV is allowed for administrators in a default install.

## The value

```php
[ 'id' => int, 'url' => string, 'filename' => string, 'mime' => string ]
```

An empty array when nothing is chosen, so `empty($file['id'])` is the test.

## Reading the file

Use the **id**, not the URL:

```php
$id = (int) ( $attributes['data']['id'] ?? 0 );

if ( $id ) {
    $path = get_attached_file( $id );
    $body = $path && is_readable( $path ) ? file_get_contents( $path ) : '';
}
```

Reading by id keeps the file on this site and on disk. Fetching the stored URL
over HTTP would make rendering a page depend on a network round trip, and on
whatever host the URL happens to name after someone edits it.

Cache the read if the file is parsed on every render. Keying the transient on
the attachment's modified time means a re-upload invalidates it without anyone
having to remember:

```php
$key = 'my_block_' . $id . '_' . get_post_modified_time( 'U', true, $id );
```

## Linking to it

```php
<a href="<?php echo esc_url( $file['url'] ); ?>" download>
    <?php echo esc_html( $file['filename'] ); ?>
</a>
```

## See also

- `repeater-control.md` — a file control inside a repeated row
- `gallery-control.md` — several images, ordered
