<?php

declare(strict_types=1);

// Getter stubs for FieldReaderTest, AliasesTest and TemplateHelperTest (global namespace, guarded).
//
//   pb_test_doing_it_wrong[]  [function, message, version] per _doing_it_wrong() call
//   pb_test_in_the_loop       bool, what in_the_loop() returns

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($fn, $message, $version)
    {
        $GLOBALS['pb_test_doing_it_wrong'][] = [$fn, $message, $version];
    }
}
if (!function_exists('in_the_loop')) {
    function in_the_loop()
    {
        return (bool) ($GLOBALS['pb_test_in_the_loop'] ?? false);
    }
}
