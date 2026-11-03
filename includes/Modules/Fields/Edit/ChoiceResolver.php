<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * The one place a field's `optionsSource` choices are resolved (the save
 * path's validator and the box payload share it). Sources are the block
 * controls' hardened options providers (`wp:posts`, `wp:terms`, theme
 * providers); their arguments come from the field's `sourceArgs` setting
 * only, never from a request.
 *
 * Results are memoised per source and arguments for the request: a repeater
 * of 500 rows asks once, not 500 times. A source that is unknown or fails
 * resolves to no options, so every submitted value is rejected (fail closed).
 */
final class ChoiceResolver
{
    private \Closure $resolve;

    /** @var array<string, list<array{key: string, label: string}>> */
    private array $memo = [];

    /** @param callable(string, array<string, mixed>): array $resolve fn(source, args): list<{key,label}> */
    public function __construct(callable $resolve)
    {
        $this->resolve = \Closure::fromCallable($resolve);
    }

    /**
     * The options of an optionsSource field; null for a field with static
     * (or no) choices; [] for an unknown or failing source.
     *
     * @return list<array{key: string, label: string}>|null
     */
    public function options(Field $f): ?array
    {
        $source = $f->setting('optionsSource');
        if (!is_string($source) || $source === '') {
            return null;
        }
        $args = $f->setting('sourceArgs', []);
        $args = is_array($args) ? $args : [];
        $memoKey = $source . "\0" . serialize($args);
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        try {
            $result = ($this->resolve)($source, $args);
        } catch (\Throwable) {
            $result = [];
        }
        $options = [];
        foreach (is_array($result) ? $result : [] as $option) {
            if (is_array($option) && isset($option['key']) && is_scalar($option['key']) && !is_bool($option['key'])) {
                $options[] = ['key' => (string) $option['key'], 'label' => is_scalar($option['label'] ?? null) ? (string) $option['label'] : (string) $option['key']];
            }
        }

        return $this->memo[$memoKey] = $options;
    }

    /**
     * Allowed value keys: the resolved source's keys, else the static
     * `choices` keys (list values for a list). ValueValidator's choice resolver.
     *
     * @return list<string>
     */
    public function allowed(Field $f): array
    {
        $options = $this->options($f);
        if ($options !== null) {
            return array_column($options, 'key');
        }
        $choices = $f->setting('choices');
        if (!is_array($choices)) {
            return [];
        }

        return array_map('strval', array_is_list($choices) ? array_values(array_filter($choices, 'is_scalar')) : array_keys($choices));
    }
}
