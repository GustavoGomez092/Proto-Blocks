<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Security\AttributeSanitizer;

/** {url, title, target}; a bare string is read as the url. */
final class LinkType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['link'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        return false;
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'target' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        $link = $this->normalize($value, $field);

        return [
            'url' => AttributeSanitizer::sanitizeUrl($link['url']),
            'title' => sanitize_text_field($link['title']),
            'target' => sanitize_text_field($link['target']) === '_blank' ? '_blank' : '',
        ];
    }

    /** @return array{url: string, title: string, target: string} */
    public function normalize(mixed $value, Field $field): mixed
    {
        if (is_string($value)) {
            $value = ['url' => $value];
        }
        $value = is_array($value) ? $value : [];

        return [
            'url' => TextType::string($value['url'] ?? ''),
            'title' => TextType::string($value['title'] ?? ''),
            'target' => TextType::string($value['target'] ?? ''),
        ];
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        $link = $this->normalize($stored, $field);
        $blank = $link['target'] === '_blank';
        $out = [
            'url' => esc_url($link['url']),
            'title' => esc_html($link['title']),
            'target' => esc_attr($blank ? '_blank' : ''),
        ];
        if ($blank) {
            $out['rel'] = 'noopener noreferrer';
        }

        return $out;
    }

    public function emptyValue(Field $field): mixed
    {
        return ['url' => '', 'title' => '', 'target' => ''];
    }
}
