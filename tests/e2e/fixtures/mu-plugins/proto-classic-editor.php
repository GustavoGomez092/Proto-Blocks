<?php
/**
 * E2E fixture (mapped by .wp-env.json to wp-content/mu-plugins): turns the
 * block editor off for every post while the option `proto_test_classic_editor`
 * is `1`, so the field box tests can run in both editors:
 *
 *   npx wp-env run tests-cli wp option update proto_test_classic_editor 1
 */

add_filter('use_block_editor_for_post', static function ($use) {
    return get_option('proto_test_classic_editor') === '1' ? false : $use;
}, 100);
