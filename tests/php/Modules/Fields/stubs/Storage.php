<?php

declare(strict_types=1);

// Storage stubs for the Task 5 tests (global namespace, guarded).

if (!function_exists('wp_slash')) {
    /**
     * Records what the stores slash. Identity on purpose: the update_*_meta
     * stubs in ../wp-stubs.php do not unslash, so a real addslashes() here
     * would break every round trip. pb_test_slashed lets a test assert that
     * the store slashes before the write.
     */
    function wp_slash($value)
    {
        $GLOBALS['pb_test_slashed'][] = $value;
        return $value;
    }
}
