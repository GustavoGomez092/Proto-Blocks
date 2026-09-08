<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Blocks\Discovery;
use ReflectionClass;

/**
 * The CLI commands reach into Discovery by name.
 *
 * `Commands` extends `WP_CLI_Command` and leans on `Plugin::getInstance()`,
 * so exercising `wp proto-blocks validate` in a unit test would mean standing
 * up WP-CLI and the whole plugin singleton. The bug this guards against does
 * not need any of that: a command called `$discovery->discoverBlocks()`, which
 * has never existed on `Discovery`, and every invocation fataled with
 * "Call to undefined method". Reading the calls out of the source and checking
 * them against the real class catches exactly that, and keeps catching it for
 * any Discovery call added later.
 */
final class DiscoveryCallsTest extends TestCase
{
    /** Every `$discovery->foo(` in Commands.php, deduplicated. */
    private function discoveryCallsInCommands(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../includes/CLI/Commands.php');

        $this->assertIsString($source, 'Commands.php should be readable');

        preg_match_all('/\$discovery->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testCommandsOnlyCallsMethodsThatExistOnDiscovery(): void
    {
        $calls = $this->discoveryCallsInCommands();

        $this->assertNotEmpty($calls, 'Commands.php should call Discovery at least once');

        $reflection = new ReflectionClass(Discovery::class);

        foreach ($calls as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                sprintf('Commands.php calls $discovery->%s() but Discovery has no such method', $method)
            );

            $this->assertTrue(
                $reflection->getMethod($method)->isPublic(),
                sprintf('Commands.php calls $discovery->%s() but that method is not public', $method)
            );
        }
    }
}
