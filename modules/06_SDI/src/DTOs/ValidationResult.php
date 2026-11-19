<?php

declare(strict_types=1);

namespace Modules\SDI\DTOs;

/**
 * Outcome of the pre-flight validation. Issues carry a stable code (official SDI code where one exists) and the
 * parameters needed to render a localized message; no human text is produced here.
 */
final class ValidationResult
{
    /**
     * @param  list<array{code: string, path: string, params: array<string, scalar|null>}>  $errors  blocking
     * @param  list<array{code: string, path: string, params: array<string, scalar|null>}>  $warnings
     */
    public function __construct(public array $errors = [], public array $warnings = []) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function error(string $code, string $path = '', array $params = []): void
    {
        $this->errors[] = ['code' => $code, 'path' => $path, 'params' => $params];
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function warning(string $code, string $path = '', array $params = []): void
    {
        $this->warnings[] = ['code' => $code, 'path' => $path, 'params' => $params];
    }

    public function merge(self $other): self
    {
        return new self([...$this->errors, ...$other->errors], [...$this->warnings, ...$other->warnings]);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['valid' => $this->passes(), 'errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
