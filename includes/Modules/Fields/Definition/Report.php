<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * Errors and warnings collected while validating or normalising field groups.
 * Each entry is `{path, code, message}`; `path` is a JSON-ish location inside
 * the raw input (`fields[3].sub_fields[1].name`, `location[0][1].param`).
 */
final class Report
{
    /** @var list<array{path: string, code: string, message: string}> */
    public array $errors = [];

    /** @var list<array{path: string, code: string, message: string}> */
    public array $warnings = [];

    public function addError(string $path, string $code, string $message): void
    {
        $this->errors[] = ['path' => $path, 'code' => $code, 'message' => $message];
    }

    public function addWarning(string $path, string $code, string $message): void
    {
        $this->warnings[] = ['path' => $path, 'code' => $code, 'message' => $message];
    }

    public function merge(Report $o): void
    {
        array_push($this->errors, ...$o->errors);
        array_push($this->warnings, ...$o->warnings);
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
