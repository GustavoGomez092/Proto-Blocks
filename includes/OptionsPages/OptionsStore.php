<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * Reads and writes the values of a settings page: one option per field, or one
 * array option holding every field. Writes exactly what it is given; the
 * caller decides which names are declared. Protected option names are refused
 * here, at the lowest layer, before anything is written.
 */
final class OptionsStore
{
    public function has(PageDefinition $page, string $name): bool
    {
        if ($this->isArrayMode($page)) {
            return array_key_exists($name, $this->arrayValue($page));
        }

        return get_option($page->optionName($name), $this->missing()) !== $this->missing();
    }

    public function read(PageDefinition $page, string $name): mixed
    {
        if ($this->isArrayMode($page)) {
            return $this->arrayValue($page)[$name] ?? null;
        }

        $value = get_option($page->optionName($name), $this->missing());

        return $value === $this->missing() ? null : $value;
    }

    /**
     * @param array<string, mixed> $values       exactly the names to write
     * @param list<string>         $booleanNames true_false fields among them
     *
     * @throws \DomainException when a target option name is protected; nothing is written then
     */
    public function save(PageDefinition $page, array $values, array $booleanNames = []): void
    {
        if ($values === []) {
            return;
        }

        if ($this->isArrayMode($page)) {
            $option = (string) $page->storageOption;
            $this->assertWritable($option);

            $current = $this->arrayValue($page);
            foreach ($values as $name => $value) {
                $name = (string) $name;
                if (in_array($name, $booleanNames, true)) {
                    $value = self::coerceLike($current[$name] ?? null, (bool) $value);
                }
                $current[$name] = $value;
            }
            update_option($option, $current, $page->autoload);

            return;
        }

        $writes = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            $option = $page->optionName($name);
            $this->assertWritable($option);
            if (in_array($name, $booleanNames, true)) {
                $existing = get_option($option, $this->missing());
                $value = self::coerceLike($existing === $this->missing() ? null : $existing, (bool) $value);
            }
            $writes[$option] = $value;
        }
        foreach ($writes as $option => $value) {
            update_option((string) $option, $value, $page->autoload);
        }
    }

    /**
     * Express a boolean in the scalar format already stored, so legacy data
     * keeps its shape. Unknown formats and absent values yield a PHP bool.
     */
    public static function coerceLike(mixed $existing, bool $value): mixed
    {
        if (is_string($existing)) {
            $pairs = [
                '1' => ['1', ''],
                '' => ['1', ''],
                '0' => ['1', '0'],
                'yes' => ['yes', 'no'],
                'no' => ['yes', 'no'],
                'on' => ['on', 'off'],
                'off' => ['on', 'off'],
                'true' => ['true', 'false'],
                'false' => ['true', 'false'],
            ];
            $key = strtolower($existing);
            if (isset($pairs[$key])) {
                return $pairs[$key][$value ? 0 : 1];
            }

            return $value;
        }

        if (is_int($existing)) {
            return ($existing === 0 || $existing === 1) ? ($value ? 1 : 0) : $value;
        }

        return $value;
    }

    private function isArrayMode(PageDefinition $page): bool
    {
        return $page->storageMode === PageDefinition::MODE_ARRAY && $page->storageOption !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(PageDefinition $page): array
    {
        $stored = get_option((string) $page->storageOption, []);

        return is_array($stored) ? $stored : [];
    }

    private function assertWritable(string $option): void
    {
        if (ProtectedOptions::isProtected($option)) {
            throw new \DomainException(sprintf('Option "%s" is reserved and cannot be written by a settings page.', $option));
        }
    }

    private function missing(): object
    {
        static $sentinel = null;

        return $sentinel ??= new \stdClass();
    }
}
