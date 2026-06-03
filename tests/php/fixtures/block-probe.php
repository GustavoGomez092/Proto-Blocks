<?php
echo 'BLOCK=' . ($block instanceof \WP_Block ? 'instance' : ($block === null ? 'null' : 'other'));
