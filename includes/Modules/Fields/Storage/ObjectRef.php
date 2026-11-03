<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Storage;

/**
 * The object a field value belongs to: a post, a term, a user or an options
 * page. `subtype` is the post type or taxonomy ('' for users and options);
 * `page` is the options page slug ('' otherwise).
 */
final class ObjectRef
{
    public const POST = 'post';
    public const TERM = 'term';
    public const USER = 'user';
    public const OPTION = 'option';

    private function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $subtype,
        public readonly string $page,
    ) {
    }

    /** The post type is read from the post; '' when the post does not exist. */
    public static function post(int $id): self
    {
        $type = $id > 0 ? get_post_type($id) : false;

        return new self(self::POST, $id, is_string($type) ? $type : '', '');
    }

    public static function term(int $id, string $taxonomy): self
    {
        return new self(self::TERM, $id, $taxonomy, '');
    }

    public static function user(int $id): self
    {
        return new self(self::USER, $id, '', '');
    }

    public static function option(string $page): self
    {
        return new self(self::OPTION, 0, '', $page);
    }

    /** A stable string for memo keys. */
    public function key(): string
    {
        return $this->kind === self::OPTION ? 'option:' . $this->page : $this->kind . ':' . $this->id . ':' . $this->subtype;
    }
}
