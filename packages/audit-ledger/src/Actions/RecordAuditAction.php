<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Actions;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Events\AuditRecordedEvent;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            ?? (app()->bound(TenantContext::class) ? app(TenantContext::class)->getTenantId() : null)
            ?? (auth()->check() ? auth()->user()->tenant_id : null)
            ?? '00000000-0000-0000-0000-000000000000'
        );

        $companyId = (string) ($model->getAttribute('company_id') ?? $tenantId);
        $resolvedModule = $moduleCode ?? $this->resolveModuleCode($model);
        $resourceType = substr($model::class, 0, 60);
        $rawKey = $model->getKey() ?: $model->getAttribute('id');
        $resourceId = (string) ($rawKey ?: Str::uuid7());
        $actorId = (string) (auth()->id() ?? 'SYSTEM');

        return $this->recordEntry(
            tenantId: $tenantId,
            companyId: $companyId,
            moduleCode: $resolvedModule,
            action: substr(strtoupper($action), 0, 50),
            resourceType: $resourceType,
            resourceId: $resourceId,
            actorUserId: auth()->id() ? (string) auth()->id() : null,
            payloadBefore: $payloadBefore,
            payloadAfter: $payloadAfter,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function executeFromPayload(array $data): AuditLedgerEntry
    {
        $tenantId = (string) ($data['tenant_id'] ?? '00000000-0000-0000-0000-000000000000');
        $companyId = (string) ($data['company_id'] ?? $tenantId);
        $resolvedModule = (string) ($data['module_code'] ?? 'CORE');
        $resourceType = substr((string) ($data['model_class'] ?? 'Unknown'), 0, 60);
        $resourceId = (string) ($data['model_id'] ?? Str::uuid7());
        $actorUserId = isset($data['actor_user_id']) ? (string) $data['actor_user_id'] : null;
        $action = substr(strtoupper((string) ($data['action'] ?? 'ACTION')), 0, 50);

        return $this->recordEntry(
            tenantId: $tenantId,
            companyId: $companyId,
            moduleCode: $resolvedModule,
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actorUserId: $actorUserId,
            payloadBefore: $data['payload_before'] ?? null,
            payloadAfter: $data['payload_after'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            userAgent: $data['user_agent'] ?? null
        );
    }

    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    private function recordEntry(
        string $tenantId,
        string $companyId,
        string $moduleCode,
        string $action,
        string $resourceType,
        string $resourceId,
        ?string $actorUserId,
        ?array $payloadBefore,
        ?array $payloadAfter,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): AuditLedgerEntry {
        $normalizedBefore = self::normalizePayload($payloadBefore);
        $normalizedAfter = self::normalizePayload($payloadAfter);

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $moduleCode,
            $action,
            $resourceType,
            $resourceId,
            $actorUserId,
            $normalizedBefore,
            $normalizedAfter,
            $ipAddress,
            $userAgent
        ): AuditLedgerEntry {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["audit_tenant_{$tenantId}"]);
            }

            $lastEntry = AuditLedgerEntry::where('tenant_id', $tenantId)
                ->orderBy('event_timestamp', 'desc')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $previousHash = $lastEntry?->record_hash ?? str_repeat('0', 64);

            $now = Carbon::now('UTC');
            if ($lastEntry && $now->lessThanOrEqualTo($lastEntry->event_timestamp)) {
                $now = $lastEntry->event_timestamp->copy()->addMicrosecond();
            }
            $timestampIso = $now->format('Y-m-d\TH:i:s\Z');

            $recordHash = self::computeRecordHash(
                previousHash: $previousHash,
                tenantId: $tenantId,
                companyId: $companyId,
                timestampIso: $timestampIso,
                moduleCode: $moduleCode,
                action: $action,
                resourceType: $resourceType,
                resourceId: $resourceId,
                actorUserId: $actorUserId,
                payloadBefore: $normalizedBefore,
                payloadAfter: $normalizedAfter
            );

            $auditId = (string) Str::uuid7();

            $entry = AuditLedgerEntry::create([
                'id' => $auditId,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'event_timestamp' => $now,
                'module_code' => $moduleCode,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'actor_user_id' => $actorUserId,
                'actor_type' => $actorUserId ? 'user' : 'system_job',
                'payload_before' => $normalizedBefore,
                'payload_after' => $normalizedAfter,
                'diff_json' => $this->calculateDiff($normalizedBefore, $normalizedAfter),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
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
        });
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
        $normalizedBefore = self::normalizePayload($payloadBefore);
        $normalizedAfter = self::normalizePayload($payloadAfter);

        $beforeJson = $normalizedBefore !== null ? json_encode($normalizedBefore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $afterJson = $normalizedAfter !== null ? json_encode($normalizedAfter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

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

    /**
     * Normalizes payload by recursively sorting keys and redacting GDPR PII.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function normalizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $redacted = self::redactPii($payload);
        self::recursiveKsort($redacted);

        return $redacted;
    }

    /**
     * Recursively redacts sensitive PII and secrets to ensure GDPR right-to-erasure compliance
     * without compromising tamper-evident ledger block hashes.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    public static function redactPii(array $array): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'secret',
            'token',
            'remember_token',
            'credit_card',
            'card_number',
            'cvv',
            'cvc',
            'api_key',
            'private_key',
            'access_token',
            'refresh_token',
            'ssn',
            'tax_code',
            'codice_fiscale',
            'iban',
            'pin',
            'auth_code',
        ];

        $result = [];
        foreach ($array as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if ($lowerKey === $sensitive || str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = self::redactPii($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Recursively sorts dictionary keys to guarantee canonical JSON serialization
     * regardless of PostgreSQL JSONB key reordering.
     *
     * @param  array<mixed, mixed>  $array
     */
    public static function recursiveKsort(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::recursiveKsort($value);
            }
        }
        ksort($array);
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
