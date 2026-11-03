<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Thrown when a JSONC declaration file cannot be decoded.
 */
final class JsoncException extends \RuntimeException
{
    /**
     * Exception::$line is protected and not readonly, so it cannot be
     * redeclared as `public readonly`; it is widened to public here instead
     * and holds the line within the JSONC text, not the PHP source line.
     */
    public int $line;

    public function __construct(string $message, int $line, public readonly int $column)
    {
        parent::__construct($message);
        $this->line = $line;
    }
}
