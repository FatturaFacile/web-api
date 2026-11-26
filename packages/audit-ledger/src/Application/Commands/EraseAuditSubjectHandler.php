<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\Events\AuditSubjectErased;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Illuminate\Support\Facades\DB;

final class EraseAuditSubjectHandler
{
    public function __construct(
        private readonly Pseudonymizer $pseudonymizer,
        private readonly AppendAuditEntryHandler $append,
    ) {}

    /**
     * @return int the number of tokens that can no longer be resolved
     */
    public function handle(EraseAuditSubjectCommand $command): int
    {
        $subjectType = substr($command->subjectType, 0, 60);

        return DB::transaction(function () use ($command, $subjectType): int {
            $erased = $this->pseudonymizer->erase($command->tenantId, $subjectType, $command->subjectId);

            // The erasure is itself a fact the ledger proves; it holds ids and a count, never the erased data.
            $this->append->handle(new AppendAuditEntryCommand(
                tenantId: $command->tenantId,
                companyId: $command->tenantId,
                moduleCode: 'PRIVACY',
                action: AuditAction::make('AUDIT_SUBJECT_ERASED'),
                resourceType: $subjectType,
                resourceId: $command->subjectId,
                after: ['erased_tokens' => $erased],
                actorUserId: $command->requestedBy,
                category: AuditCategory::Platform,
            ));

            AuditSubjectErased::dispatch($command->tenantId, $subjectType, $command->subjectId, $erased);

            return $erased;
        });
    }
}
