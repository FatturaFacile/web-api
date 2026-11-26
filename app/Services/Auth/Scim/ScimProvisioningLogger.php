<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\ScimGroup;
use App\Models\ScimProvisioningLog;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The provisioning log of the SCIM operations of a tenant (what the identity provider asked and how it went) and the
 * sync summary shown next to the SCIM token. Only writes are logged (create, replace, patch, delete); a failure is also
 * written to the audit ledger. Rows hold ids and display names, never attribute values.
 */
final class ScimProvisioningLogger
{
    public const SUCCESS = 'success';

    public const FAILURE = 'failure';

    public function __construct(private readonly RegistrationAudit $audit) {}

    public function success(string $tenantId, string $resource, string $operation, ?string $resourceId, ?string $resourceName, ?string $tokenId): void
    {
        $this->write($tenantId, $resource, $operation, self::SUCCESS, $resourceId, $resourceName, $tokenId, null);
    }

    public function failure(string $tenantId, string $resource, string $operation, ?string $resourceId, ?string $resourceName, ?string $tokenId, string $error): void
    {
        $this->write($tenantId, $resource, $operation, self::FAILURE, $resourceId, $resourceName, $tokenId, $error);

        $this->audit->record(RegistrationAudit::SCIM_OPERATION_FAILED, null, [
            'scim_token' => $tokenId,
            'resource' => $resource,
            'resource_id' => $resourceId !== null && Str::isUuid($resourceId) ? $resourceId : null,
            'operation' => $operation,
            'reason' => Str::limit($error, 200, ''),
        ], $tenantId);
    }

    /**
     * Last sync time, how many users and groups the identity provider provisioned, and the last error.
     *
     * @return array{last_sync_at: string|null, last_status: string|null, users_provisioned: int, groups_provisioned: int, last_error: array<string, mixed>|null}
     */
    public function summary(string $tenantId): array
    {
        $last = ScimProvisioningLog::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderByDesc('created_at')->orderByDesc('id')->first();
        $lastError = ScimProvisioningLog::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', self::FAILURE)->orderByDesc('created_at')->orderByDesc('id')->first();

        return [
            'last_sync_at' => $last?->created_at?->toIso8601String(),
            'last_status' => $last?->status,
            'users_provisioned' => DB::table('tenant_user')->where('tenant_id', $tenantId)->where('provisioned_via', 'scim')->count(),
            'groups_provisioned' => ScimGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'last_error' => $lastError === null ? null : [
                'message' => $lastError->error,
                'resource' => $lastError->resource,
                'operation' => $lastError->operation,
                'at' => $lastError->created_at?->toIso8601String(),
            ],
        ];
    }

    private function write(string $tenantId, string $resource, string $operation, string $status, ?string $resourceId, ?string $resourceName, ?string $tokenId, ?string $error): void
    {
        $log = new ScimProvisioningLog([
            'resource' => $resource,
            'operation' => $operation,
            'status' => $status,
            'resource_id' => $resourceId !== null && Str::isUuid($resourceId) ? $resourceId : null,
            'resource_name' => $resourceName === null ? null : Str::limit($resourceName, 250, ''),
            'scim_token_id' => $tokenId !== null && Str::isUuid($tokenId) ? $tokenId : null,
            'error' => $error === null ? null : Str::limit($error, 1000, ''),
        ]);
        $log->forceFill(['tenant_id' => $tenantId])->save();
    }
}
