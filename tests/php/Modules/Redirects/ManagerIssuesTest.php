<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use ProtoBlocks\CLI\ContentModelCommands;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;

/**
 * `wp proto-blocks validate` reads Manager::issues() (Commands::finishValidation()),
 * which already includes the redirects module's issues: no CLI edit needed.
 */
final class ManagerIssuesTest extends ManagerBootTestCase
{
    public function testRedirectChainWarningReachesManagerIssues(): void
    {
        $this->putGroup('legacy', self::groupText([
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/b', 'to' => '/c'],
        ]));
        $issues = $this->bootManager()->issues();

        $chain = array_values(array_filter($issues, static fn($i): bool => $i->code === 'redirect_chain'));
        self::assertCount(1, $chain);
        self::assertSame('warning', $chain[0]->level);
        self::assertSame('redirects', $chain[0]->kind);
        self::assertSame('legacy', $chain[0]->slug);

        // The rows `validate` prints from those issues.
        $child = $this->child;
        $rows = ContentModelCommands::validationRows(
            new ContentModelRegistry(new FileLoader(), static fn(): array => [$child]),
            $issues
        );
        $row = array_values(array_filter($rows, static fn(array $r): bool => $r['code'] === 'redirect_chain'));
        self::assertCount(1, $row);
        self::assertSame(['redirects', 'legacy', 'warning'], [$row[0]['kind'], $row[0]['slug'], $row[0]['level']]);
    }

    public function testBrokenGroupFileErrorReachesManagerIssues(): void
    {
        $this->putGroup('broken', '{ "rules": [ ');
        self::assertContains('error:invalid_jsonc', self::issueCodes($this->bootManager()->issues()));
    }

    public function testDisabledModuleContributesNoRedirectIssues(): void
    {
        $this->putGroup('legacy', self::groupText([['from' => '/a', 'to' => '/b'], ['from' => '/b', 'to' => '/c']]));
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn(array $s): array => ['redirects' => false] + $s;
        self::assertNotContains('warning:redirect_chain', self::issueCodes($this->bootManager()->issues()));
    }
}
