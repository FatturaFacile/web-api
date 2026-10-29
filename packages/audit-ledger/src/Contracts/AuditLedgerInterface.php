<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AuditLedgerInterface
{
    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public function record(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?string $moduleCode = null
    ): void;
}
