<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Jobs\ExportAuditPackageJob;
use Enterprise\AuditLedger\Models\AuditExport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExportAuditHandler
{
    public function __construct(private readonly AppendAuditEntryHandler $append) {}

    /**
     * @return array{export: AuditExport, created: bool}
     */
    public function handle(ExportAuditCommand $command): array
    {
        $from = Carbon::parse($command->from, 'UTC')->startOfDay();
        $to = Carbon::parse($command->to, 'UTC')->startOfDay();
        $maxDays = (int) config('audit_ledger.export.max_window_days', 366);

        if ($to->lessThan($from) || $from->diffInDays($to) > $maxDays) {
            throw new InvalidArgumentException("The export window must be between 0 and {$maxDays} days.");
        }

        if ($command->idempotencyKey !== null) {
            $existing = AuditExport::query()->where('tenant_id', $command->tenantId)->where('idempotency_key', $command->idempotencyKey)->first();

            if ($existing instanceof AuditExport) {
                return ['export' => $existing, 'created' => false];
            }
        }

        try {
            /** @var AuditExport $export */
            $export = DB::transaction(function () use ($command, $from, $to): AuditExport {
                $export = AuditExport::query()->create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $command->tenantId,
                    'requested_by' => $command->requestedBy,
                    'idempotency_key' => $command->idempotencyKey,
                    'status' => AuditExport::STATUS_QUEUED,
                    'from_date' => $from->toDateString(),
                    'to_date' => $to->toDateString(),
                    'filters' => $command->filters === [] ? null : $command->filters,
                ]);

                // Exporting the ledger is itself an access the ledger proves.
                $this->append->handle(new AppendAuditEntryCommand(
                    tenantId: $command->tenantId,
                    companyId: $command->tenantId,
                    moduleCode: 'AUDIT',
                    action: AuditAction::make('AUDIT_EXPORT_REQUESTED'),
                    resourceType: 'AuditExport',
                    resourceId: (string) $export->id,
                    after: ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'filters' => array_keys($command->filters)],
                    actorUserId: $command->requestedBy,
                    category: AuditCategory::Access,
                ));

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced: the idempotency key returns the one that won.
            $winner = AuditExport::query()->where('tenant_id', $command->tenantId)->where('idempotency_key', $command->idempotencyKey)->firstOrFail();

            return ['export' => $winner, 'created' => false];
        }

        ExportAuditPackageJob::dispatch((string) $export->id)->afterCommit();

        return ['export' => $export, 'created' => true];
    }
}
