<?php
/**
 * Options probe (E2E). proto_field() escapes text values itself; nothing
 * here escapes again, so the spec proves the getter does it.
 */
?>
<div class="pb-options-probe">
    <p class="pb-probe-phone"><?php echo proto_field('phone', 'option:theme-settings'); ?></p>
    <p class="pb-probe-copyright"><?php echo proto_field('copyright', 'option:theme-settings-footer'); ?></p>
</div>
