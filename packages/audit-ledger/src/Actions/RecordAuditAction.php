<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Actions;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryHandler;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\AuditIds;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry point of the synchronous append (see AuditLedgerService) and home of the frozen formula of the version 1 hash.
 *
 * Entries written before the forensic extension (`hash_version` 1) were hashed with plain SHA-256 over a pipe separated
 * string whose payloads were normalised by the three static methods below. Those methods must never change: they are
 * what keeps those entries verifiable. New entries (version 2) use the keyed chain of Domain\Services\ChainHasher.
 */
final class RecordAuditAction
{
    public function __construct(private readonly AppendAuditEntryHandler $handler) {}

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
            ?? AuditIds::NIL_TENANT
        );

        $personal = method_exists($model, 'auditPersonalAttributes') ? $model->auditPersonalAttributes() : [];
        $excluded = method_exists($model, 'auditExcludedAttributes') ? $model->auditExcludedAttributes() : [];

        return $this->handler->handle(AppendAuditEntryCommand::forModel(
            action: $action,
            model: $model,
            before: $payloadBefore,
            after: $payloadAfter,
            tenantId: $tenantId,
            companyId: (string) ($model->getAttribute('company_id') ?? $tenantId),
            moduleCode: $moduleCode ?? $this->resolveModuleCode($model),
            personalAttributes: $personal,
            excludedAttributes: $excluded,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function executeFromPayload(array $data): AuditLedgerEntry
    {
        return $this->handler->handle(AppendAuditEntryCommand::fromPayload($data));
    }

    /**
     * Deterministically calculates the SHA-256 of the version 1 entries (frozen, see the class comment).
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
     * Frozen: normalises a version 1 payload by recursively sorting keys and redacting PII.
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
     * Frozen: the redaction rules the version 1 entries were written with (substring match on purpose: changing it
     * would change the recomputed hash of entries already in the chain). New entries use PayloadSanitizer.
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
     * Frozen: recursively sorts dictionary keys.
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
}
