<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Services;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Jobs\ProcessAuditLogJob;
use Illuminate\Database\Eloquent\Model;

final class AuditLedgerService implements AuditLedgerInterface
{
    public function __construct(
        private readonly RecordAuditAction $recordAction
    ) {}

    public function record(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?string $moduleCode = null
    ): void {
        if (config('erp_foundation.audit.async', false)) {
            ProcessAuditLogJob::dispatch([
                'action' => $action,
                'model_class' => $model::class,
                'model_id' => (string) $model->getKey(),
                'tenant_id' => (string) ($model->getAttribute('tenant_id') ?? '00000000-0000-0000-0000-000000000000'),
                'company_id' => (string) ($model->getAttribute('company_id') ?? $model->getAttribute('tenant_id') ?? '00000000-0000-0000-0000-000000000000'),
                'payload_before' => $payloadBefore,
                'payload_after' => $payloadAfter,
                'module_code' => $moduleCode,
                'actor_user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } else {
            $this->recordAction->execute($action, $model, $payloadBefore, $payloadAfter, $moduleCode);
        }
    }
}
