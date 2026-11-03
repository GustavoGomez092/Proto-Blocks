<?php
/**
 * The `$template` helper object every block template receives.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use ProtoBlocks\Modules\Fields\Read\FieldReader;

final class TemplateHelper
{
    /**
     * @param array<string, mixed> $attrs Processed block attributes.
     * @param \WP_Block|null       $block The block instance on the front end, null in the editor preview.
     */
    public function __construct(
        private readonly array $attrs,
        private readonly ?\WP_Block $block = null,
    ) {
    }

    public function has_value(string $name): bool
    {
        return !empty($this->attrs[$name]);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attrs[$name] ?? $default;
    }

    /**
     * A field of the post the block renders for (escaped unless $raw): the
     * block's postId context (Query Loop), else the current post, else the
     * queried object. null when the Fields module is off or the name is not
     * declared for that post.
     */
    public function field(string $name, bool $raw = false): mixed
    {
        $reader = FieldReader::shared();

        return $reader === null ? null : $reader->get($name, $this->postId(), $raw);
    }

    /** A positive post ID, or null to let the reader use the current object. */
    private function postId(): ?int
    {
        $context = $this->block !== null && is_array($this->block->context ?? null) ? $this->block->context : [];
        $fromBlock = self::positiveInt($context['postId'] ?? null);
        if ($fromBlock !== null) {
            return $fromBlock;
        }

        return function_exists('get_the_ID') ? self::positiveInt(get_the_ID()) : null;
    }

    private static function positiveInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $v) === 1) {
            return (int) $v;
        }

        return null;
    }
}
