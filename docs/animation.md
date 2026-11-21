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

**CSS hides. JavaScript only reveals.** Key the hidden state to
`html.proto-js` (the runtime adds that class to `<html>` from `<head>`, before
anything is painted) so it applies the instant the block exists, however it
arrived, and never applies with JS off:

```css
html.proto-js .my-block[data-proto-animate="manual"] [data-my-part] {
  opacity: 0;
  visibility: hidden;
}
```
```js
gsap.set(parts, { autoAlpha: 0, y: 24 });            // start states inline first
section.setAttribute('data-proto-animate', 'done');  // lifts the CSS, same tick
gsap.to(parts, { autoAlpha: 1, y: 0, stagger: 0.08 });
```

Never hide from a script, including an inline `<script>` inside the block that
adds a pending class while the section is parsed. It works on a full load, but
a page transition (proto-blocks-theme's Taxi) imports the new view with its
scripts inert and runs them after the markup is in the document, so the
finished block shows for a frame, vanishes and animates in. Reproduce by
navigating to the page through a link, not by reloading it.

## Hooking the reveal moment (`proto-blocks:reveal` event)
When the runtime reveals an element (sets it to `done`), it dispatches a bubbling
`proto-blocks:reveal` CustomEvent on that element. Listen for it if you want to
start a `view.js` animation exactly when an element comes into view, without
wiring your own IntersectionObserver:
```js
document.querySelectorAll('[data-proto-animate]').forEach((el) => {
  el.addEventListener('proto-blocks:reveal', () => {
    // el just flipped to done — kick off your animation here
  });
});
```
The event fires once per element. It fires for `pending` (runtime-triggered),
`manual` (when you or the watchdog set `done`), and the reduced-motion / no-IO
fast path — so a listener always runs regardless of how the element was revealed.

## Guarantees (you get these for free)
- Scrolls into view → revealed.
- `prefers-reduced-motion` → revealed instantly, no motion.
- JS disabled → a fallback style keyed to `html:not(.proto-js)` reveals
  content. (Not a `<noscript>`: page transitions parse fetched pages with
  scripting off, which turned a `<noscript>` style into a live one.)
- Your JS fails / never completes → watchdog reveals after a grace period. For
  `manual` the grace (1.5 s) starts once the page has loaded (capped at 10 s),
  not when the block enters view, so a slow load cannot reveal the block before
  its `view.js` has even arrived.
- Editor → resting state (no `pending` added), fully editable.

Legacy `data-animate` is accepted as an alias of `data-proto-animate`.
