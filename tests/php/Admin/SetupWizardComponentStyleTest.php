<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\SetupWizard;

/**
 * The wizard's component style drives which demo blocks get written into
 * the theme; an unknown value must collapse to the default, never widen.
 */
final class SetupWizardComponentStyleTest extends TestCase
{
    public function testKnownValuesPassThrough(): void
    {
        self::assertSame('vanilla', SetupWizard::normalizeComponentStyle('vanilla'));
        self::assertSame('tailwind', SetupWizard::normalizeComponentStyle('tailwind'));
    }

    public function testCaseAndWhitespaceAreNormalized(): void
    {
        self::assertSame('tailwind', SetupWizard::normalizeComponentStyle(' Tailwind '));
    }

    /**
     * @dataProvider unknownValues
     */
    public function testUnknownValuesFallBackToVanilla(string $value): void
    {
        self::assertSame('vanilla', SetupWizard::normalizeComponentStyle($value));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownValues(): iterable
    {
        yield 'empty' => [''];
        yield 'all (the installer\'s widest mode)' => ['all'];
        yield 'random' => ['bootstrap'];
        yield 'traversal-ish' => ['../tailwind'];
        yield 'html' => ['<script>'];
    }

    public function testEnumMatchesInstallerTypesMinusAll(): void
    {
        foreach (SetupWizard::COMPONENT_STYLES as $style) {
            self::assertContains($style, \ProtoBlocks\Admin\AdminPage::DEMO_TYPES);
        }
        self::assertNotContains('all', SetupWizard::COMPONENT_STYLES);
    }
}
