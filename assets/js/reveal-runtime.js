/**
 * Proto-Blocks reveal runtime (frontend only).
 *
 * Owns the data-proto-animate lifecycle and GUARANTEES content is never left
 * hidden. States: "pending" (runtime reveals on scroll-in) -> "done";
 * "manual" (a block's own view.js owns the motion; runtime only backstops).
 * Legacy "data-animate" is treated as an alias.
 */
(function () {
  'use strict';

  var ATTR = 'data-proto-animate';
  var LEGACY = 'data-animate';
  var MANUAL_GRACE = 1500; // ms a manual block gets to reveal itself after entering view
  var LOAD_BACKSTOP = 2000; // ms after window load before force-revealing in/above-viewport stragglers
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function stateOf(el) {
    if (el.hasAttribute(ATTR)) return el.getAttribute(ATTR);
    if (el.hasAttribute(LEGACY)) return el.getAttribute(LEGACY);
    return null;
  }

  function setDone(el) {
    if (stateOf(el) === 'done') return;
    if (el.hasAttribute(ATTR)) el.setAttribute(ATTR, 'done');
    if (el.hasAttribute(LEGACY)) el.setAttribute(LEGACY, 'done');
    try { el.dispatchEvent(new CustomEvent('proto-blocks:reveal', { bubbles: true })); } catch (e) {}
  }

  function all() { return document.querySelectorAll('[' + ATTR + '],[' + LEGACY + ']'); }

  function revealAll() { Array.prototype.forEach.call(all(), setDone); }

  function boot() {
    // No motion possible / wanted -> reveal everything now.
    if (reduced || !('IntersectionObserver' in window)) { revealAll(); return; }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target, st = stateOf(el);
        if (st === 'pending') {
          setDone(el);
          io.unobserve(el);
        } else if (st === 'manual') {
          // Block owns the animation; only backstop if it never finishes.
          io.unobserve(el);
          window.setTimeout(function () { if (stateOf(el) !== 'done') setDone(el); }, MANUAL_GRACE);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.01 });

    Array.prototype.forEach.call(all(), function (el) {
      if (stateOf(el) !== 'done') io.observe(el);
    });

    // Final safety: anything still hidden that is in/above the viewport after load
    // (e.g. IO never fired, manual block JS missing) gets revealed. Far-below
    // content stays pending so genuine scroll reveals still happen.
    function scheduleBackstop() {
      window.setTimeout(function () {
        var vh = window.innerHeight || document.documentElement.clientHeight;
        Array.prototype.forEach.call(all(), function (el) {
          if (stateOf(el) === 'done') return;
          if (el.getBoundingClientRect().top < vh) setDone(el);
        });
      }, LOAD_BACKSTOP);
    }
    if (document.readyState === 'complete') {
      scheduleBackstop();
    } else {
      window.addEventListener('load', scheduleBackstop);
    }
  }

  // Intro overlay (a theme's once-per-session preloader, such as proto-blocks-theme's `.proto-intro`): while it is
  // up, nothing is revealed and no watchdog runs, so motion that waits for the overlay is not snapped to its end
  // underneath it. The theme dispatches `proto:intro-complete` on document when it hands control back (its own
  // safety net fires it too); a 10 s backstop here covers an overlay that never does.
  var INTRO_BACKSTOP = 10000;
  function introActive() {
    var html = document.documentElement;
    if (html.classList.contains('proto-intro-skip')) return false;
    var overlay = document.querySelector('.proto-intro');
    return !!(overlay && !overlay.classList.contains('is-hidden') && html.classList.contains('proto-intro-active'));
  }
  function bootAfterIntro() {
    if (!introActive()) { boot(); return; }
    var booted = false;
    function go() { if (booted) return; booted = true; boot(); }
    document.addEventListener('proto:intro-complete', go, { once: true });
    window.setTimeout(go, INTRO_BACKSTOP);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAfterIntro);
  } else {
    bootAfterIntro();
  }
})();
