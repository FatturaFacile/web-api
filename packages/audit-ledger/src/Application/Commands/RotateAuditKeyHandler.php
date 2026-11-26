<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Models\AuditKeyVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RotateAuditKeyHandler
{
    public function __construct(
        private readonly AuditKeyring $keyring,
        private readonly AppendAuditEntryHandler $append,
    ) {}

    public function handle(RotateAuditKeyCommand $command): void
    {
        $current = $this->keyring->activeVersion($command->tenantId);

        if ($command->newVersion <= $current) {
            throw new InvalidArgumentException("The new key version must be higher than the one in force ({$current}).");
        }

        if (! $this->keyring->hasVersion($command->newVersion)) {
            throw new InvalidArgumentException("The master key of version {$command->newVersion} is not configured.");
        }

        DB::transaction(function () use ($command, $current): void {
            // The rotation is the last entry signed with the old key: the proof of when the new key took over.
            $this->append->handle(new AppendAuditEntryCommand(
                tenantId: $command->tenantId,
                companyId: $command->tenantId,
                moduleCode: 'AUDIT',
                action: AuditAction::make('AUDIT_KEY_ROTATED'),
                resourceType: 'AuditChain',
                resourceId: $command->tenantId,
                after: ['from_key_version' => $current, 'to_key_version' => $command->newVersion],
                actorUserId: $command->requestedBy,
                category: AuditCategory::Platform,
            ));

            AuditKeyVersion::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $command->tenantId,
                'key_version' => $command->newVersion,
                'activated_by' => $command->requestedBy,
                'activated_at' => Carbon::now('UTC'),
            ]);
        });

        $this->keyring->forgetTenant($command->tenantId);
    }
}
