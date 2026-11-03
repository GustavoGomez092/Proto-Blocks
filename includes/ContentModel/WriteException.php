<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * A refused or failed declaration write, carrying a machine code and an HTTP status.
 */
final class WriteException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }
}
