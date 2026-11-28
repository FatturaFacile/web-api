<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use Modules\TimeTravel\Domain\ValueObjects\MerkleIntegrityProof;
use Modules\TimeTravel\Services\MerkleAuditService;

/**
 * Compliance certificate (GeBüV / Art. 957a CO, Art. 2214 c.c.): recomputes the hash chains and the Merkle roots of a range of dates.
 */
final readonly class VerifyMerkleAuditChainHandler
{
    public function __construct(private MerkleAuditService $audit) {}

    public function handle(VerifyMerkleAuditChainQuery $query): MerkleIntegrityProof
    {
        return $this->audit->verify($query->tenantId, $query->from, $query->to);
    }
}
