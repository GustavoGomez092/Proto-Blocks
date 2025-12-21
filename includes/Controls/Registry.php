<?php
/**
 * Control Registry - Manages control type registration
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Controls;

/**
 * Registry for control types used in block inspector panels
 */
class Registry
{
    /**
     * Registered control types
     *
     * @var array<string, array>
     */
    private array $types = [];

    /**
     * Register a control type
     *
     * @param string $type Control type identifier
     * @param array $config Control configuration
     */
    public function register(string $type, array $config): void
    {
        $this->types[$type] = $this->normalizeConfig($type, $config);
    }

    /**
     * Check if a control type is registered
     */
    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Get a control type configuration
     */
    public function get(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * Get all registered control types
     */
    public function getAll(): array
    {
        return $this->types;
    }

    /**
     * Get data type for a control
     */
    public function getDataType(string $type): string
    {
        return $this->types[$type]['data_type'] ?? 'string';
    }

    /**
     * Get default value for a control type
     */
    public function getDefault(string $type): mixed
    {
        return $this->types[$type]['default'] ?? null;
    }

    /**
     * Process a control value
     *
     * @param string $type Control type
     * @param mixed $value Raw value
     * @param array $config Control configuration
     * @return mixed Processed value
     */
    public function processValue(string $type, mixed $value, array $config = []): mixed
    {
        $controlConfig = $this->get($type);

        if (!$controlConfig) {
            return $value;
        }

        // Call process_value callback if exists
        if (isset($controlConfig['process_value']) && is_callable($controlConfig['process_value'])) {
            return $controlConfig['process_value']($value, $config);
        }

        return $value;
    }

    /**
     * Sanitize a control value
     *
     * @param string $type Control type
     * @param mixed $value Raw value
     * @param array $config Control configuration
     * @return mixed Sanitized value
     */
    public function sanitize(string $type, mixed $value, array $config = []): mixed
    {
        $controlConfig = $this->get($type);

        if (!$controlConfig) {
            return $value;
        }

        // Call sanitize callback if exists
        if (isset($controlConfig['sanitize']) && is_callable($controlConfig['sanitize'])) {
            return $controlConfig['sanitize']($value, $config);
        }

        // Default sanitization based on data type
        return match ($controlConfig['data_type'] ?? 'string') {
            'boolean' => (bool) $value,
            'number' => is_numeric($value) ? (float) $value : 0,
            'integer' => (int) $value,
            'string' => sanitize_text_field((string) $value),
            'object' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    /**
     * Normalize control configuration
     */
    private function normalizeConfig(string $type, array $config): array
    {
        return array_merge([
            'type' => $type,
            'label' => ucfirst($type),
            'description' => '',
            'data_type' => 'string',
            'default' => null,
            'sanitize' => null,
            'validate' => null,
            'process_value' => null,
        ], $config);
    }

    /**
     * Get control types for JavaScript
     */
    public function getForJavaScript(): array
    {
        $controls = [];

        foreach ($this->types as $type => $config) {
            $controls[$type] = [
                'type' => $type,
                'label' => $config['label'],
                'data_type' => $config['data_type'],
                'default' => $config['default'],
            ];
        }

        return $controls;
    }

    /**
     * Build attribute from control config
     */
    public function buildAttribute(string $name, array $config): array
    {
        $type = $config['type'] ?? 'text';
        $controlConfig = $this->get($type) ?? ['data_type' => 'string'];

        $attribute = [
            'type' => $this->mapDataType($controlConfig['data_type']),
        ];

        if (isset($config['default'])) {
            $attribute['default'] = $config['default'];
        } elseif (isset($controlConfig['default'])) {
            $attribute['default'] = $controlConfig['default'];
        }

        return $attribute;
    }

    /**
     * Map internal data types to WordPress attribute types
     */
    private function mapDataType(string $dataType): string
    {
        return match ($dataType) {
            'string' => 'string',
            'number', 'integer', 'float' => 'number',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }
}
