<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * Thrown when an options page declaration is not valid. Carries every problem
 * found, not just the first.
 */
final class InvalidDeclaration extends \InvalidArgumentException
{
    /** @var list<array{path: string, message: string}> */
    private array $errors;

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);
        parent::__construct(
            $errors === [] ? 'Invalid options page declaration.' : implode(' ', array_column($errors, 'message'))
        );
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
