<?php
/**
 * Fixture: a block whose root element carries a template-authored class and
 * style, so the wrapper tests can prove attribute-derived id/class/style are
 * sanitised while the template's own values are kept verbatim.
 */
?>
<div class="pb-wrapper-fixture md:flex" data-proto-block="wrapper-fixture" style="--tpl-var: 1"><p>body</p></div>
