<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\ValueObjects;

use Modules\LeaveManagement\Exceptions\LeaveManagementException;

/**
 * Italian INPS telematic medical certificate protocol number (PUC — 9 alphanumeric characters).
 */
final readonly class InpsProtocolNumber
{
    public string $value;

    private function __construct(string $normalized)
    {
        $this->value = $normalized;
    }

    public static function fromString(string $raw): self
    {
        $normalized = strtoupper(trim($raw));

        if (preg_match('/^[A-Z0-9]{9}$/', $normalized) !== 1) {
            throw LeaveManagementException::invalidInpsProtocol($raw);
        }

        return new self($normalized);
    }

    public static function tryFromString(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return self::fromString($raw);
    }
}
