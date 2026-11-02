<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BrowserCompiler;
use ProtoBlocks\Tailwind\Cache;
use ProtoBlocks\Tailwind\Scoper;

/**
 * CSS posted by the browser compiler is served to every visitor and injected
 * into the block editor, so store_css must refuse anything that is not plain,
 * reasonably sized CSS. AdminSettings::handleStoreCss() calls validate() and
 * BrowserCompiler::store() re-checks it.
 */
final class StoreCssValidationTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pb-storecss-' . uniqid() . '/';
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cacheDir . '.work');
        @rmdir($this->cacheDir);
    }

    public function test_plain_css_is_accepted(): void
    {
        $this->assertNull(BrowserCompiler::validate('.flex{display:flex}.bg-\[url\(\/img\.png\)\]{background-image:url(/img.png)}'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenCss(): array
    {
        return [
            'closing style tag'      => ['.a{color:red}</style><script>alert(1)</script>'],
            'closing style spaced'   => ['.a{color:red}< / STYLE >'],
            'script tag'             => ['.a{color:red}<script src=x>'],
            'expression()'           => ['.a{width:expression(alert(1))}'],
            'expression spaced'      => ['.a{width:EXPRESSION (alert(1))}'],
            'javascript: url'        => ['.a{background:url(javascript:alert(1))}'],
            'javascript spaced'      => ['.a{background:url(javascript :alert(1))}'],
            '@import url(http'       => ['@import url(http://evil.test/x.css);.a{}'],
            '@import url("https'     => ['@import url("https://evil.test/x.css");'],
            '@import "http'          => ['@import "https://evil.test/x.css";'],
            '-moz-binding'           => ['.a{-moz-binding:url(x.xml#y)}'],
            'behavior'               => ['.a{behavior:url(x.htc)}'],
            'NUL byte'               => [".a{color:red}\0"],
        ];
    }

    /**
     * @dataProvider forbiddenCss
     */
    public function test_dangerous_patterns_are_rejected(string $css): void
    {
        $this->assertNotNull(BrowserCompiler::validate($css));
    }

    public function test_empty_css_is_rejected(): void
    {
        $this->assertNotNull(BrowserCompiler::validate("  \n "));
    }

    public function test_oversize_css_is_rejected(): void
    {
        $tooBig = str_repeat('.a{color:red}', (int) ceil((BrowserCompiler::DEFAULT_MAX_BYTES + 1) / 13));
        $this->assertGreaterThan(BrowserCompiler::DEFAULT_MAX_BYTES, strlen($tooBig));

        $error = BrowserCompiler::validate($tooBig);

        $this->assertNotNull($error);
        $this->assertStringContainsString('too large', $error);
    }

    public function test_css_at_the_limit_is_accepted(): void
    {
        $rule = '.a{color:red}';
        $atLimit = str_repeat($rule, intdiv(BrowserCompiler::DEFAULT_MAX_BYTES, strlen($rule)));

        $this->assertNull(BrowserCompiler::validate($atLimit));
    }

    public function test_store_refuses_rejected_css_and_writes_nothing(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $this->assertFalse($compiler->store('.a{color:red}</style><script>x</script>'));
        $this->assertNotNull($compiler->getLastError());
        $this->assertFileDoesNotExist($this->cacheDir . 'tailwind.css');
    }

    public function test_store_does_not_persist_unscoped_css_when_pcre_fails(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $ok = $compiler->store(str_repeat('.a{color:red}', 50));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertFalse($ok);
        $this->assertStringContainsString('PCRE', (string) $compiler->getLastError());
        $this->assertFileDoesNotExist($this->cacheDir . 'tailwind.css', 'unscoped CSS must never be written');
    }

    public function test_store_guards_the_output_directory(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $this->assertTrue($compiler->store('.flex{display:flex}'));

        $this->assertFileExists($this->cacheDir . 'index.php');
        $this->assertFileExists($this->cacheDir . '.htaccess');
        $htaccess = (string) file_get_contents($this->cacheDir . '.htaccess');
        // Everything denied...
        $this->assertStringContainsString('Require all denied', $htaccess);
        // ...except stylesheets, which are enqueued by URL.
        $this->assertMatchesRegularExpression('/<FilesMatch "\\\\\.css\$">\s*Require all granted/', $htaccess);
    }
}
