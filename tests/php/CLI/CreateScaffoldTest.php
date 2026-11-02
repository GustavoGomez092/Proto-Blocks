<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\Commands;

require_once __DIR__ . '/wp-stubs.php';

/**
 * `wp proto-blocks create`: argument validation and the template it scaffolds.
 */
final class CreateScaffoldTest extends TestCase
{
    private function scaffold(array $fields, string $name = 'feature'): string
    {
        return (new Commands())->generateTemplate($name, $fields);
    }

    // ---- block slug -------------------------------------------------------

    public function testAcceptsALowercaseDashedSlug(): void
    {
        $this->assertSame('feature-card', Commands::normalizeBlockName('feature-card'));
        $this->assertSame('hero-section', Commands::normalizeBlockName('Hero Section'));
        $this->assertSame('col2', Commands::normalizeBlockName('col2'));
    }

    /** @dataProvider invalidSlugs */
    public function testRejectsSlugsWordPressWouldRefuseToRegister(string $raw): void
    {
        $this->assertNull(Commands::normalizeBlockName($raw));
    }

    public static function invalidSlugs(): array
    {
        return [
            'underscore'    => ['my_block'],
            'leading digit' => ['2col'],
            'percent'       => ['a%b'],
            'empty'         => [''],
            'only symbols'  => ['---'],
        ];
    }

    public function testTraversalCannotSurviveSlugNormalisation(): void
    {
        $name = Commands::normalizeBlockName('../../wp-config.php');

        if (null !== $name) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $name);
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('.', $name);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    // ---- field names ------------------------------------------------------

    /** @dataProvider validFieldNames */
    public function testAcceptsIdentifierFieldNames(string $name): void
    {
        $this->assertNull(Commands::validateFieldName($name));
    }

    public static function validFieldNames(): array
    {
        return [
            ['title'],
            ['backgroundColor'],
            ['item_2'],
            // Default wysiwyg field name; the engine never defines a $content local.
            ['content'],
        ];
    }

    /** @dataProvider invalidFieldNames */
    public function testRejectsNonIdentifierAndReservedFieldNames(string $name): void
    {
        $this->assertNotNull(Commands::validateFieldName($name));
    }

    public static function invalidFieldNames(): array
    {
        $cases = [
            'dash'          => ['my-field'],
            'leading digit' => ['1st'],
            'empty'         => [''],
            'uppercase'     => ['Title'],
            'space'         => ['my field'],
        ];

        foreach (Commands::RESERVED_FIELD_NAMES as $reserved) {
            $cases['reserved ' . $reserved] = [$reserved];
        }

        return $cases;
    }

    public function testParseFieldsBuildsConfigsKeyedByName(): void
    {
        $fields = Commands::parseFields('title:text, body:wysiwyg,hero:image,more:link,accent:color');

        $this->assertSame(['title', 'body', 'hero', 'more', 'accent'], array_keys($fields));
        $this->assertSame('h2', $fields['title']['tagName']);
        $this->assertSame('#000000', $fields['accent']['default']);
    }

    public function testParseFieldsThrowsOnAReservedName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'this'");

        Commands::parseFields('this:text');
    }

    public function testParseFieldsThrowsOnANonIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Commands::parseFields('my-field:text');
    }

    // ---- terminal output --------------------------------------------------

    public function testSafeArgStripsControlAndEscapeCharacters(): void
    {
        $forged = "\e[2K\e[1A\e[2KSuccess: All blocks validated.\x00\x7f";

        $this->assertSame('[2K[1A[2KSuccess: All blocks validated.', Commands::safeArg($forged));
    }

    // ---- scaffolded template ---------------------------------------------

    public function testScaffoldStartsWithAnAbspathGuard(): void
    {
        $php = $this->scaffold(['title' => ['type' => 'text', 'tagName' => 'h2']]);

        $this->assertStringStartsWith("<?php\n", $php);
        $this->assertStringContainsString("defined( 'ABSPATH' ) || exit;", $php);
    }

    public function testInnerBlocksScaffoldEchoesTheEngineVariableNotContent(): void
    {
        $php = $this->scaffold(['body' => ['type' => 'inner-blocks']]);

        $this->assertStringContainsString('data-proto-inner-blocks', $php);
        $this->assertStringContainsString('echo $innerBlocksContent ?? \'\';', $php);
        $this->assertStringNotContainsString('echo $content', $php);
        $this->assertStringNotContainsString('$body =', $php, 'inner-blocks must not get a local of its own');
        $this->assertStringNotContainsString('wp_kses_post( $innerBlocksContent', $php);
    }

    public function testWysiwygContentAndInnerBlocksCoexistWithoutShadowing(): void
    {
        $php = $this->scaffold([
            'content' => ['type' => 'wysiwyg'],
            'body'    => ['type' => 'inner-blocks'],
        ]);

        $this->assertSame(1, substr_count($php, "\$content = \$attributes['content'] ?? '';"));
        $this->assertStringContainsString('echo wp_kses_post( $content );', $php);
        $this->assertStringContainsString('echo $innerBlocksContent ?? \'\';', $php);
        // The wysiwyg value must never be echoed a second time without kses.
        $this->assertSame(0, preg_match('/echo \$content;/', $php));
    }

    public function testColourControlIsValidatedWithSanitizeHexColorNotEscAttr(): void
    {
        $php = $this->scaffold(['accent' => ['type' => 'color', 'default' => '#ff0000']]);

        $this->assertStringContainsString("\$accent = sanitize_hex_color( \$attributes['accent'] ?? '' ) ?: '#ff0000';", $php);
        $this->assertStringContainsString("'--wp-block-proto-blocks-feature-accent: ' . \$accent", $php);
        $this->assertStringNotContainsString('esc_attr( $accent', $php);
    }

    public function testColourDefaultThatIsNotHexFallsBackToBlack(): void
    {
        $php = $this->scaffold(['accent' => ['type' => 'color-palette', 'default' => "'; system('id'); //"]]);

        $this->assertStringContainsString("?: '#000000';", $php);
        $this->assertStringNotContainsString("system('id')", $php);
    }

    public function testEveryEchoInTheDefaultScaffoldIsEscapedForItsContext(): void
    {
        $php = $this->scaffold([
            'title'   => ['type' => 'text', 'tagName' => 'h2'],
            'content' => ['type' => 'wysiwyg'],
            'image'   => ['type' => 'image'],
            'link'    => ['type' => 'link'],
        ]);

        $this->assertStringContainsString('echo esc_html( $title );', $php);
        $this->assertStringContainsString('echo wp_kses_post( $content );', $php);
        $this->assertStringContainsString("echo esc_url( \$image['url'] );", $php);
        $this->assertStringContainsString("echo esc_attr( \$image['alt'] ?? '' );", $php);
        $this->assertStringContainsString("echo esc_url( \$link['url'] ?? '#' );", $php);
        $this->assertStringContainsString('echo proto_blocks_link_attrs( $link );', $php);
        $this->assertStringNotContainsString("esc_attr( \$link['target'] )", $php);

        // Only the wrapper attributes (pre-escaped by core) may be echoed bare.
        preg_match_all('/echo\s+\$([a-zA-Z_]+)\s*;/', $php, $bare);
        $this->assertSame(['wrapper_attributes'], array_values(array_unique($bare[1])));
    }

    // ---- template helpers -------------------------------------------------

    public function testSanitizeColorReturnsHexOrDefault(): void
    {
        $this->assertSame('#abc', proto_blocks_sanitize_color('#abc', '#000'));
        $this->assertSame('#AABBCC', proto_blocks_sanitize_color(' #AABBCC ', '#000'));
        $this->assertSame('#fff', proto_blocks_sanitize_color('red;position:fixed;inset:0', '#fff'));
        $this->assertSame('#fff', proto_blocks_sanitize_color('abc', '#fff'));
        $this->assertSame('', proto_blocks_sanitize_color(null, ''));
        $this->assertSame('', proto_blocks_sanitize_color('', ''));
    }

    public function testCssUrlNeutralisesCharactersThatCouldCloseTheToken(): void
    {
        $token = proto_blocks_css_url("https://x.example/a.png);position:fixed;inset:0;background:url(https://evil.example/b.png");

        $this->assertStringStartsWith("url('", $token);
        $this->assertStringEndsWith("')", $token);
        $inner = substr($token, 5, -2);
        $this->assertSame(0, preg_match('/[()\'";\s]/', $inner), 'inner url must contain no CSS-significant characters: ' . $inner);
        $this->assertStringContainsString('%29%3Bposition:fixed', $inner);
    }

    public function testCssUrlEncodesQuotesEvenInEscUrlSpelling(): void
    {
        $this->assertSame("url('https://x.example/a%27b.png')", proto_blocks_css_url("https://x.example/a'b.png"));
        $this->assertSame("url('https://x.example/a.png?x=1&y=2')", proto_blocks_css_url('https://x.example/a.png?x=1&amp;y=2'));
    }

    public function testCssUrlReturnsEmptyWhenEscUrlRejectsTheUrl(): void
    {
        $this->assertSame('', proto_blocks_css_url('javascript:alert(1)'));
        $this->assertSame('', proto_blocks_css_url(''));
    }

    public function testHexToRgbaRejectsNonHexAndClampsAlpha(): void
    {
        $this->assertSame('rgba(255, 255, 255, 0.5)', proto_blocks_hex_to_rgba('#fff', 0.5));
        $this->assertSame('rgba(30, 30, 30, 1)', proto_blocks_hex_to_rgba('1e1e1e', 2));
        $this->assertSame('rgba(0, 0, 0, 0.7)', proto_blocks_hex_to_rgba('zzz;position:fixed', 0.7));
        $this->assertSame('rgba(0, 0, 0, 0)', proto_blocks_hex_to_rgba('', -1));
    }

    public function testLinkAttrsForcesNoopenerOnBlankTargets(): void
    {
        $this->assertSame(' target="_blank" rel="noopener"', proto_blocks_link_attrs(['target' => '_blank']));
        $this->assertSame(
            ' target="_blank" rel="nofollow noopener noreferrer"',
            proto_blocks_link_attrs(['target' => '_blank', 'rel' => 'nofollow noopener noreferrer'])
        );
        $this->assertSame(' rel="nofollow"', proto_blocks_link_attrs(['target' => '_self', 'rel' => 'nofollow']));
        $this->assertSame('', proto_blocks_link_attrs([]));
        $this->assertSame('', proto_blocks_link_attrs(['target' => '_top', 'rel' => '" onmouseover="alert(1)']));
    }
}
