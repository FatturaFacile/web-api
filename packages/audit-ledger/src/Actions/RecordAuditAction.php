<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Actions;

use App\Models\Tenant;
use Enterprise\AuditLedger\Events\AuditRecordedEvent;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class RecordAuditAction
{
    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public function execute(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?string $moduleCode = null
    ): AuditLedgerEntry {
        $tenantId = (string) (
            $model->getAttribute('tenant_id')
            ?? ($model instanceof Tenant ? $model->getKey() : null)
            ?? (auth()->check() ? auth()->user()->tenant_id : null)
            ?? '00000000-0000-0000-0000-000000000000'
        );

        $companyId = (string) ($model->getAttribute('company_id')
            ?? $tenantId);

        $now = Carbon::now('UTC');
        $timestampIso = $now->format('Y-m-d\TH:i:s\Z');

        $lastEntry = AuditLedgerEntry::where('tenant_id', $tenantId)
            ->latest('event_timestamp')
            ->first();

        $previousHash = $lastEntry?->record_hash ?? str_repeat('0', 64);
        $resolvedModule = $moduleCode ?? $this->resolveModuleCode($model);
        $resourceType = substr($model::class, 0, 60);
        $rawKey = $model->getKey() ?: $model->getAttribute('id');
        $resourceId = (string) ($rawKey ?: Str::uuid7());
        $actorId = (string) (auth()->id() ?? 'SYSTEM');

        $recordHash = self::computeRecordHash(
            previousHash: $previousHash,
            tenantId: $tenantId,
            companyId: $companyId,
            timestampIso: $timestampIso,
            moduleCode: $resolvedModule,
            action: strtoupper($action),
            resourceType: $resourceType,
            resourceId: $resourceId,
            actorUserId: $actorId,
            payloadBefore: $payloadBefore,
            payloadAfter: $payloadAfter
        );

        $auditId = (string) Str::uuid7();

        $entry = AuditLedgerEntry::create([
            'id' => $auditId,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'event_timestamp' => $now,
            'module_code' => $resolvedModule,
            'action' => strtoupper($action),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'actor_user_id' => auth()->id(),
            'actor_type' => auth()->check() ? 'user' : 'system_job',
            'payload_before' => $payloadBefore,
            'payload_after' => $payloadAfter,
            'diff_json' => $this->calculateDiff($payloadBefore, $payloadAfter),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'record_hash' => $recordHash,
            'previous_hash' => $previousHash,
        ]);

        AuditRecordedEvent::dispatch(
            $auditId,
            $tenantId,
            $action,
            $resourceType,
            $resourceId,
            $recordHash
        );

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function executeFromPayload(array $data): AuditLedgerEntry
    {
        $tenantId = (string) ($data['tenant_id'] ?? '00000000-0000-0000-0000-000000000000');
        $companyId = (string) ($data['company_id'] ?? $tenantId);

        $now = Carbon::now('UTC');
        $timestampIso = $now->format('Y-m-d\TH:i:s\Z');

        $lastEntry = AuditLedgerEntry::where('tenant_id', $tenantId)
            ->latest('event_timestamp')
            ->first();

        $previousHash = $lastEntry?->record_hash ?? str_repeat('0', 64);
        $resolvedModule = (string) ($data['module_code'] ?? 'CORE');
        $resourceType = substr((string) ($data['model_class'] ?? 'Unknown'), 0, 60);
        $resourceId = (string) ($data['model_id'] ?? Str::uuid7());
        $actorId = (string) ($data['actor_user_id'] ?? 'SYSTEM');
        $action = strtoupper((string) ($data['action'] ?? 'ACTION'));

        $recordHash = self::computeRecordHash(
            previousHash: $previousHash,
            tenantId: $tenantId,
            companyId: $companyId,
            timestampIso: $timestampIso,
            moduleCode: $resolvedModule,
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actorUserId: $actorId,
            payloadBefore: $data['payload_before'] ?? null,
            payloadAfter: $data['payload_after'] ?? null
        );

        $auditId = (string) Str::uuid7();

        return AuditLedgerEntry::create([
            'id' => $auditId,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'event_timestamp' => $now,
            'module_code' => $resolvedModule,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'actor_type' => ! empty($data['actor_user_id']) ? 'user' : 'system_job',
            'payload_before' => $data['payload_before'] ?? null,
            'payload_after' => $data['payload_after'] ?? null,
            'diff_json' => $this->calculateDiff($data['payload_before'] ?? null, $data['payload_after'] ?? null),
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'record_hash' => $recordHash,
            'previous_hash' => $previousHash,
        ]);
    }

    /**
     * Deterministically calculates SHA-256 hash across all immutable audit block parameters.
     *
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public static function computeRecordHash(
        string $previousHash,
        string $tenantId,
        string $companyId,
        string $timestampIso,
        string $moduleCode,
        string $action,
        string $resourceType,
        string $resourceId,
        ?string $actorUserId,
        ?array $payloadBefore,
        ?array $payloadAfter
    ): string {
        $beforeJson = $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $afterJson = $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        $canonical = implode('|', [
            $previousHash,
            $tenantId,
            $companyId,
            $timestampIso,
            $moduleCode,
            $action,
            $resourceType,
            $resourceId,
            $actorUserId ?? 'SYSTEM',
            $beforeJson,
            $afterJson,
        ]);

        return hash('sha256', $canonical);
    }

    private function resolveModuleCode(Model $model): string
    {
        $class = $model::class;
        $needle = 'Modules\\';
        if (str_contains($class, $needle)) {
            $parts = explode('\\', $class);

            return strtoupper($parts[1] ?? 'ERP');
        }

        return 'CORE';
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<string, mixed>|null
     */
    private function calculateDiff(?array $before, ?array $after): ?array
    {
        if ($before === null || $after === null) {
            return null;
        }

        $diff = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $diff[$key] = [
                    'old' => $before[$key] ?? null,
                    'new' => $value,
                ];
            }
        }

        return ! empty($diff) ? $diff : null;
    }
}
