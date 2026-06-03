<?php
echo 'BLOCK=' . (isset($block) && $block instanceof \WP_Block ? 'instance' : ($block === null ? 'null' : 'unset'));
