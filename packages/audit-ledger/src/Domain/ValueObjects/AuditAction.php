<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * The name of what happened, upper case and at most 50 characters (CREATED, UPDATED, LOGIN_FAILED, TENANT_RESTORED...).
 */
final readonly class AuditAction implements Stringable
{
    public const int MAX_LENGTH = 50;

    private function __construct(public string $value) {}

    public static function make(string $action): self
    {
        $normalized = substr(strtoupper(trim($action)), 0, self::MAX_LENGTH);

        if ($normalized === '') {
            throw new InvalidArgumentException('An audit action cannot be empty.');
        }

        return new self($normalized);
    }

    /**
     * A read-only event (somebody looked at or exported data) never changes a business record: when the ledger is
     * unavailable it is queued for retry instead of blocking the request. Every other action fails closed.
     */
    public function isReadOnly(): bool
    {
        return in_array($this->value, ['VIEWED', 'EXPORTED', 'DOWNLOADED', 'PRINTED', 'SENSITIVE_VIEWED'], true)
            || str_ends_with($this->value, '_VIEWED')
            || str_ends_with($this->value, '_DOWNLOADED');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
