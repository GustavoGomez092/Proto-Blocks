<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\Commands;
use ProtoBlocks\Schema\SchemaValidator;

/**
 * Per-block validation, as `wp proto-blocks validate` reports it.
 *
 * The command used to call `$validation->isValid()` on what `SchemaValidator`
 * actually returns -- a bool -- so it fataled with "Call to a member function
 * isValid() on bool" the moment it reached its first block. The validator
 * signals failure by throwing, not by handing back a result object, and these
 * tests pin the row the command builds for each outcome against the real
 * validator.
 */
final class ValidateBlockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pb-validate-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function writeBlockJson(string $contents): void
    {
        file_put_contents($this->dir . '/block.json', $contents);
    }

    public function testReportsMissingBlockJson(): void
    {
        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('block.json', $row['message']);
    }

    public function testReportsInvalidJson(): void
    {
        $this->writeBlockJson('{ not json');

        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame('error', $row['status']);
        $this->assertStringContainsStringIgnoringCase('json', $row['message']);
    }

    public function testReportsAValidBlockAsValid(): void
    {
        $this->writeBlockJson(json_encode([
            'name' => 'proto-blocks/tidy',
            'protoBlocks' => [
                'template' => 'template.php',
                'fields' => ['heading' => ['type' => 'text']],
            ],
        ]));

        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame('valid', $row['status'], 'message was: ' . $row['message']);
    }

    public function testReportsAWarningWhenTheNameIsNotNamespaced(): void
    {
        $this->writeBlockJson(json_encode([
            'name' => 'nonamespace',
            'protoBlocks' => [
                'template' => 'template.php',
                'fields' => ['heading' => ['type' => 'text']],
            ],
        ]));

        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame('warning', $row['status']);
        $this->assertStringContainsString('namespace', $row['message']);
    }

    public function testReportsAnErrorWhenTheNameIsMissing(): void
    {
        $this->writeBlockJson(json_encode([
            'protoBlocks' => [
                'template' => 'template.php',
                'fields' => ['heading' => ['type' => 'text']],
            ],
        ]));

        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('name', $row['message']);
    }

    public function testNamesTheRowAfterTheBlockDirectory(): void
    {
        $this->writeBlockJson(json_encode(['name' => 'proto-blocks/tidy', 'protoBlocks' => []]));

        $row = Commands::validateBlockAt($this->dir, new SchemaValidator());

        $this->assertSame(basename($this->dir), $row['block']);
    }
}
