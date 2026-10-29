<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

final class ProcurementAuditLedgerService
{
    public function __construct(
        private readonly ?AuditLedgerInterface $enterpriseAuditService = null
    ) {}

    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public function record(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null
    ): void {
        if ($this->enterpriseAuditService !== null) {
            $this->enterpriseAuditService->record(
                action: strtoupper($action),
                model: $model,
                payloadBefore: $payloadBefore,
                payloadAfter: $payloadAfter,
                moduleCode: 'PROCUREMENT'
            );
        }
    }
}
