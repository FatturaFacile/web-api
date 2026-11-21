<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Crypto;

/**
 * What a ciphertext is bound to. The additional authenticated data makes a ciphertext copied to another tenant, entity,
 * table or record fail authentication (ciphertext transplant attack).
 */
final readonly class EncryptionContext
{
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $table,
        public string $recordId,
    ) {}

    public function aad(): string
    {
        return implode("\x1f", [$this->tenantId, $this->legalEntityId ?? '-', $this->table, $this->recordId]);
    }
}
