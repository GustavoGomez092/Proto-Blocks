<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * A group's location rules in ACF's OR-of-ANDs shape. A group with any rule
 * whose param is outside the supported grammar is not renderable anywhere:
 * it fails closed rather than falling open onto every screen.
 */
final class Location
{
    /**
     * @param list<list<array{param: string, operator: string, value: string}>> $or
     * @param list<string> $unsupported rule params not in GroupNormalizer::LOCATION_PARAMS
     */
    public function __construct(
        public readonly array $or,
        public readonly bool $implied = false,
        public readonly array $unsupported = [],
    ) {
    }

    public function isRenderable(): bool
    {
        return $this->unsupported === [] && $this->or !== [];
    }
}
