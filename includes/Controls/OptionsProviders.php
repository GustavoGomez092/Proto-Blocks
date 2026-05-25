<?php
/**
 * Options Providers - Resolves server-provided options for dynamic controls.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Controls;

/**
 * Registry mapping an options "source" identifier to a callback that returns
 * an array of { key, label } options for a dynamic select control.
 */
class OptionsProviders
{
    /**
     * @var array<string, array{callback: callable, allowed_args: array<int, string>}>
     */
    private array $providers = [];

    /**
     * Register an options provider.
     *
     * @param string             $name        Source identifier, e.g. "wp:posts".
     * @param callable            $callback    fn(array $args): array — returns {key,label}[] or a key=>label map.
     * @param array<int, string>  $allowedArgs Whitelist of arg keys forwarded to the callback. Empty = allow all.
     */
    public function register(string $name, callable $callback, array $allowedArgs = []): void
    {
        $this->providers[$name] = [
            'callback' => $callback,
            'allowed_args' => $allowedArgs,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @return array{callback: callable, allowed_args: array<int, string>}|null
     */
    public function get(string $name): ?array
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return array<string, array{callback: callable, allowed_args: array<int, string>}>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Resolve a source to normalized options.
     *
     * @param array<string, mixed> $args
     * @return array<int, array{key: string, label: string}>
     * @throws \InvalidArgumentException When the source is not registered.
     */
    public function resolve(string $name, array $args = []): array
    {
        $provider = $this->get($name);

        if ($provider === null) {
            throw new \InvalidArgumentException(
                sprintf('Unknown options source "%s"', $name)
            );
        }

        $allowed = $provider['allowed_args'];
        $filtered = empty($allowed)
            ? $args
            : array_intersect_key($args, array_flip($allowed));

        $result = ($provider['callback'])($filtered);

        if (!is_array($result)) {
            return [];
        }

        return self::normalizeOptions($result);
    }

    /**
     * Normalize a {key,label}[] list or a key=>label map to {key,label}[].
     *
     * @param array<mixed> $options
     * @return array<int, array{key: string, label: string}>
     */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $normalized[] = [
                    'key' => (string) ($value['key'] ?? $value['value'] ?? $value['label'] ?? $key),
                    'label' => (string) ($value['label'] ?? $value['value'] ?? $value['key'] ?? $key),
                ];
            } else {
                $normalized[] = [
                    'key' => (string) (is_int($key) ? $value : $key),
                    'label' => (string) $value,
                ];
            }
        }

        return $normalized;
    }
}
