<?php

declare(strict_types=1);

// Getter stubs for FieldReaderTest, AliasesTest and TemplateHelperTest (global namespace, guarded).
// _doing_it_wrong() is in tests/php/bootstrap.php (pb_test_doing_it_wrong[]).
//
//   pb_test_in_the_loop       bool, what in_the_loop() returns

if (!function_exists('in_the_loop')) {
    function in_the_loop()
    {
        return (bool) ($GLOBALS['pb_test_in_the_loop'] ?? false);
    }
}
