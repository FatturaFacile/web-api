<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Events\FiscalDocumentLockedEvent;
use Modules\Workflow\Models\WorkflowInstance;

final class DocumentLockingBridgeService
{
    public function executeFiscalLock(WorkflowInstance $instance): void
    {
        $approvable = $instance->approvable;

        if ($approvable instanceof LockableByWorkflowInterface) {
            $approvable->lockByWorkflow($instance);
            $instance->update(['locked_fiscal_document' => true]);

            event(new FiscalDocumentLockedEvent(
                documentType: $approvable::class,
                documentId: (string) $approvable->getKey(),
                instanceId: $instance->id,
                tenantId: $instance->tenant_id,
                companyId: (string) ($instance->company_id ?? $instance->tenant_id)
            ));
        }
    }
}
