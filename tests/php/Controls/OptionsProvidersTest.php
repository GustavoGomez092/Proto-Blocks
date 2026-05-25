<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Controls;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Controls\OptionsProviders;

final class OptionsProvidersTest extends TestCase
{
    public function test_register_and_has(): void
    {
        $providers = new OptionsProviders();
        $providers->register('demo', fn(array $args) => []);

        $this->assertTrue($providers->has('demo'));
        $this->assertFalse($providers->has('missing'));
    }

    public function test_resolve_returns_normalized_options_from_callback(): void
    {
        $providers = new OptionsProviders();
        $providers->register('demo', fn(array $args) => [
            ['key' => '1', 'label' => 'One'],
            ['key' => '2', 'label' => 'Two'],
        ]);

        $this->assertSame(
            [
                ['key' => '1', 'label' => 'One'],
                ['key' => '2', 'label' => 'Two'],
            ],
            $providers->resolve('demo')
        );
    }

    public function test_resolve_normalizes_map_and_list_shapes(): void
    {
        $providers = new OptionsProviders();
        $providers->register('map', fn(array $args) => ['usd' => 'US Dollar', 'eur' => 'Euro']);

        $this->assertSame(
            [
                ['key' => 'usd', 'label' => 'US Dollar'],
                ['key' => 'eur', 'label' => 'Euro'],
            ],
            $providers->resolve('map')
        );
    }

    public function test_resolve_filters_args_to_allowed_keys(): void
    {
        $captured = [];
        $providers = new OptionsProviders();
        $providers->register('demo', function (array $args) use (&$captured) {
            $captured = $args;
            return [];
        }, ['post_type']);

        $providers->resolve('demo', ['post_type' => 'page', 'evil' => 'rm -rf']);

        $this->assertSame(['post_type' => 'page'], $captured);
    }

    public function test_resolve_passes_all_args_when_no_whitelist(): void
    {
        $captured = [];
        $providers = new OptionsProviders();
        $providers->register('demo', function (array $args) use (&$captured) {
            $captured = $args;
            return [];
        });

        $providers->resolve('demo', ['anything' => 'goes']);

        $this->assertSame(['anything' => 'goes'], $captured);
    }

    public function test_resolve_throws_on_unknown_source(): void
    {
        $providers = new OptionsProviders();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown options source "nope"');
        $providers->resolve('nope');
    }

    public function test_resolve_returns_empty_array_when_callback_returns_non_array(): void
    {
        $providers = new OptionsProviders();
        $providers->register('bad', fn(array $args) => null);

        $this->assertSame([], $providers->resolve('bad'));
    }

    public function test_normalize_cross_borrows_when_key_or_label_missing(): void
    {
        $providers = new OptionsProviders();
        $providers->register('partial', fn(array $args) => [
            ['key' => 'slug-only'],
            ['label' => 'Label Only'],
        ]);

        $this->assertSame(
            [
                ['key' => 'slug-only', 'label' => 'slug-only'],
                ['key' => 'Label Only', 'label' => 'Label Only'],
            ],
            $providers->resolve('partial')
        );
    }
}
