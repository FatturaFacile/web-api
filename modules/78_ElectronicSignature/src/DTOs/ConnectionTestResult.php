<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * Result of the "test connection" of a provider configuration. `code` is a translation key suffix
 * (`signatures.test.<code>`), `detail` is technical text from the provider and is never translated.
 */
final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public ?string $detail = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'code' => $this->code, 'detail' => $this->detail];
    }
}
