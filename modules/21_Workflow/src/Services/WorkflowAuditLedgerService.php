<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Support\Str;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

final class WorkflowAuditLedgerService
{
    public function __construct(
        private readonly ?AuditLedgerService $enterpriseAuditService = null
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        WorkflowInstance $instance,
        string $eventType,
        array $payload,
        ?WorkflowInstanceStep $step = null,
        ?string $actorUserId = null
    ): WorkflowAuditEntry {
        $lastEntry = WorkflowAuditEntry::where('workflow_instance_id', $instance->id)
            ->orderBy('id', 'desc')
            ->first();
        $prevSignature = $lastEntry?->hmac_signature ?? 'GENESIS';

        $secret = config('app.key', 'workflow_audit_salt');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hmacSignature = hash_hmac('sha256', $prevSignature.'|'.$instance->id.'|'.$eventType.'|'.$payloadJson, $secret);

        $entry = WorkflowAuditEntry::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $instance->tenant_id,
            'company_id' => $instance->company_id,
            'workflow_instance_id' => $instance->id,
            'workflow_instance_step_id' => $step?->id,
            'event_type' => $eventType,
            'event_payload' => $payload,
            'actor_user_id' => $actorUserId ?? auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'hmac_signature' => $hmacSignature,
            'created_at' => now(),
        ]);

        // Forward to central EnterpriseAuditLedger if available
        if ($this->enterpriseAuditService !== null) {
            $this->enterpriseAuditService->record(
                action: strtoupper($eventType),
                model: $instance,
                payloadBefore: null,
                payloadAfter: $payload,
                moduleCode: 'WORKFLOW'
            );
        }

        return $entry;
    }
}
