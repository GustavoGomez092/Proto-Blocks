<?php
/**
 * Event card (E2E). The field getters escape; nothing here escapes again.
 */
?>
<div class="pb-event-card">
    <p class="pb-event-subtitle"><?php echo $template->field('subtitle'); ?></p>
    <ul class="pb-event-agenda">
        <?php foreach (proto_rows('agenda') as $row) : ?>
            <li><?php echo $row['title']; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
