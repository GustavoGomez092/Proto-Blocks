<?php
/**
 * Field Registry - Manages field type registration
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields;

/**
 * Registry for field types
 *
 * Allows core and third-party field types to be registered
 */
class Registry
{
    /**
     * Registered field types
     *
     * @var array<string, array>
     */
    private array $types = [];

    /**
     * Register a field type
     *
     * @param string $type Field type identifier (e.g., 'text', 'image')
     * @param array $config Field type configuration
     * @throws \InvalidArgumentException If type is already registered
     */
    public function register(string $type, array $config): void
    {
        if ($this->has($type)) {
            throw new \InvalidArgumentException(
                sprintf('Field type "%s" is already registered', $type)
            );
        }

        $this->types[$type] = $this->normalizeConfig($type, $config);
    }

    /**
     * Check if a field type is registered
     */
    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Get a field type configuration
     *
     * @param string $type Field type identifier
     * @return array|null Field configuration or null if not found
     */
    public function get(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * Get all registered field types
     *
     * @return array<string, array>
     */
    public function getAll(): array
    {
        return $this->types;
    }

    /**
     * Get the PHP class for a field type
     */
    public function getClass(string $type): ?string
    {
        return $this->types[$type]['php_class'] ?? null;
    }

    /**
     * Get a field type instance
     */
    public function getInstance(string $type): ?FieldInterface
    {
        $class = $this->getClass($type);

        if ($class === null) {
            return null;
        }

        if (!class_exists($class)) {
            throw new \RuntimeException(
                sprintf('Field type class "%s" for type "%s" does not exist', $class, $type)
            );
        }

        // Return the class name - fields use static methods
        return null; // Static classes don't need instances
    }

    /**
     * Update a DOM element with a field value
     *
     * @param string $type Field type
     * @param \DOMElement $element DOM element
     * @param mixed $value Field value
     * @param array $config Field configuration
     */
    public function updateElement(string $type, \DOMElement $element, mixed $value, array $config = []): void
    {
        $class = $this->getClass($type);

        if ($class === null || !class_exists($class)) {
            // Fallback to text field
            $class = Types\TextField::class;
        }

        $class::updateElement($element, $value, $config);
    }

    /**
     * Extract default value from a DOM element
     *
     * @param string $type Field type
     * @param \DOMElement $element DOM element
     * @return mixed Default value
     */
    public function extractDefault(string $type, \DOMElement $element): mixed
    {
        $class = $this->getClass($type);

        if ($class === null || !class_exists($class)) {
            return '';
        }

        return $class::extractDefault($element);
    }

    /**
     * Get attribute schema for a field type
     *
     * @param string $type Field type
     * @param mixed $default Default value
     * @param array $config Field configuration
     * @return array Attribute schema
     */
    public function getAttributeSchema(string $type, mixed $default = null, array $config = []): array
    {
        $class = $this->getClass($type);

        if ($class === null || !class_exists($class)) {
            return ['type' => 'string', 'default' => $default ?? ''];
        }

        return $class::getAttributeSchema($default, $config);
    }

    /**
     * Normalize field type configuration
     */
    private function normalizeConfig(string $type, array $config): array
    {
        return array_merge([
            'type' => $type,
            'php_class' => null,
            'js_component' => null,
            'attribute_schema' => ['type' => 'string', 'default' => ''],
            'sanitize' => null,
            'validate' => null,
            'label' => ucfirst($type),
        ], $config);
    }

    /**
     * Get field types for JavaScript
     *
     * Returns configuration that can be passed to the editor
     */
    public function getForJavaScript(): array
    {
        $types = [];

        foreach ($this->types as $type => $config) {
            $types[$type] = [
                'type' => $type,
                'label' => $config['label'],
                'js_component' => $config['js_component'],
                'attribute_schema' => $config['attribute_schema'],
            ];
        }

        return $types;
    }
}
