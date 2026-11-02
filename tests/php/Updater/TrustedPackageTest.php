<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Updater;

require_once __DIR__ . '/stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Updater\GitHubUpdater;

final class TrustedPackageTest extends TestCase
{
    private const GOOD = 'https://github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.15.0/proto-blocks-2.15.0.zip';

    /** @return array<string,array{string,bool}> */
    public static function urls(): array
    {
        return [
            'own release asset'              => [self::GOOD, true],
            'own .sha256 sidecar'            => [self::GOOD . '.sha256', true],
            'owner/repo case-insensitive'    => ['https://GitHub.com/gustavogomez092/proto-blocks/releases/download/v2.15.0/proto-blocks-2.15.0.zip', true],
            'http scheme'                    => ['http://github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.15.0/x.zip', false],
            'no scheme'                      => ['github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.15.0/x.zip', false],
            'other github repo'              => ['https://github.com/attacker/evil/releases/download/v1/x.zip', false],
            'github subdomain'               => ['https://api.github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.15.0/x.zip', false],
            'objects.githubusercontent.com'  => ['https://objects.githubusercontent.com/github-production-release-asset-2e65be/1/2?x=y', false],
            'lookalike host'                 => ['https://github.com.evil.example/GustavoGomez092/Proto-Blocks/releases/download/v1/x.zip', false],
            'userinfo trick'                 => ['https://github.com@evil.example/GustavoGomez092/Proto-Blocks/releases/download/v1/x.zip', false],
            'embedded credentials'           => ['https://user:pw@github.com/GustavoGomez092/Proto-Blocks/releases/download/v1/x.zip', false],
            'non-default port'               => ['https://github.com:8443/GustavoGomez092/Proto-Blocks/releases/download/v1/x.zip', false],
            'repo root (not a download)'     => ['https://github.com/GustavoGomez092/Proto-Blocks', false],
            'releases page, not download'    => ['https://github.com/GustavoGomez092/Proto-Blocks/releases/tag/v2.15.0', false],
            'archive tarball'                => ['https://github.com/GustavoGomez092/Proto-Blocks/archive/refs/tags/v2.15.0.zip', false],
            'prefix only, no asset'          => ['https://github.com/GustavoGomez092/Proto-Blocks/releases/download/', false],
            'dot-dot traversal'              => ['https://github.com/GustavoGomez092/Proto-Blocks/releases/download/../../attacker/evil/releases/download/v1/x.zip', false],
            'percent-encoded path'           => ['https://github.com/GustavoGomez092/Proto-Blocks/releases/download/%2e%2e/x.zip', false],
            'double slash in path'           => ['https://github.com/GustavoGomez092/Proto-Blocks/releases/download//v1/x.zip', false],
            'empty'                          => ['', false],
            'garbage'                        => ['not a url at all', false],
            'javascript scheme'              => ['javascript:alert(1)', false],
        ];
    }

    /** @dataProvider urls */
    public function test_is_trusted_package(string $url, bool $expected): void
    {
        $this->assertSame($expected, GitHubUpdater::is_trusted_package($url), $url);
    }
}
