# Animating a block: the `data-proto-animate` convention

Proto-Blocks ships a frontend reveal runtime that owns a simple lifecycle and
**guarantees content is never left hidden**.

## States
- `pending` — author's pre-reveal state. The runtime reveals it (sets `done`)
  when it scrolls into view. Use for CSS-only reveals.
- `manual` — your block's own `view.js` owns the motion. The runtime does NOT
  trigger it; it only backstops (force-reveals if your JS never finishes).
- `done` — revealed. The runtime sets this; your CSS/JS react to it.

## Author a CSS-only ("auto") reveal — no JS
```php
<section <?php echo get_block_wrapper_attributes(['class' => 'my-block']); ?>
  <?php echo $is_preview ? '' : 'data-proto-animate="pending"'; ?>>
```
```css
.my-block[data-proto-animate="pending"] { opacity: 0; transform: translateY(16px); }
.my-block[data-proto-animate="done"]    { opacity: 1; transform: none; transition: opacity .6s, transform .6s; }
```
`$is_preview = ! isset($block) || $block === null;` — omit the attribute in the
editor so content is visible/editable; the runtime handles the rest on the frontend.

## Author a JS ("manual") reveal — your own GSAP/anime timeline
Set the root to `manual`, hide children in CSS while not `done`, run your
timeline in `view.js`, then set `data-proto-animate="done"`.

## Guarantees (you get these for free)
- Scrolls into view → revealed.
- `prefers-reduced-motion` → revealed instantly, no motion.
- JS disabled → `<noscript>` reveals content.
- Your JS fails / never completes → watchdog reveals after a grace period.
- Editor → resting state (no `pending` added), fully editable.

Legacy `data-animate` is accepted as an alias of `data-proto-animate`.
