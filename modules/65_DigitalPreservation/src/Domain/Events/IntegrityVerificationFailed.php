<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Events;

/**
 * A verification found a mismatch (bit-rot, tampering, broken chain).
 */
final readonly class IntegrityVerificationFailed
{
    /**
     * @param  list<array{type: string, reference: string, expected: ?string, actual: ?string}>  $failures
     */
    public function __construct(public string $tenantId, public string $batchId, public array $failures) {}
}
