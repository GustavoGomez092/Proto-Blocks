<?php
/**
 * Fixture: a block template that escapes untrusted values the way a correctly
 * written template does, so the tests can prove the renderer's post-processing
 * leaves that escaping intact.
 *
 * The unit-test bootstrap stubs esc_html() as an identity function and has no
 * esc_attr()/esc_js(), so the fixture applies the same transformations those
 * WordPress helpers apply (htmlspecialchars with ENT_QUOTES / ENT_COMPAT).
 */
$pb_esc_attr = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$pb_esc_html = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$pb_esc_js   = static fn (string $s): string => htmlspecialchars(addslashes($s), ENT_COMPAT, 'UTF-8');

/** @var string $attr_payload   value destined for an attribute */
/** @var string $text_payload   value destined for a text node */
/** @var string $js_payload     value destined for a JS string literal */
/** @var string $raw_markup     emitted verbatim (simulates a template echoing pre-encoded entities) */
$attr_payload = $attr_payload ?? '';
$text_payload = $text_payload ?? '';
$js_payload   = $js_payload ?? '';
$raw_markup   = $raw_markup ?? '';
?>
<div class="pb-escaping-fixture" data-proto-block="escaping-fixture" data-note="<?php echo $pb_esc_attr($attr_payload); ?>" title="® — “t”">
    <p class="text"><?php echo $pb_esc_html($text_payload); ?></p>
    <p class="chars">® — “curly” ‘single’ &nbsp;end</p>
    <p class="raw"><?php echo $raw_markup; ?></p>
    <script>var q = "<?php echo $pb_esc_js($js_payload); ?>"; var snow = "☃";</script>
</div>
