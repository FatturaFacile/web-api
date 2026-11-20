<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Queries\GetPreservationDashboardStatsQuery;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationAuditLog;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationCandidateCollector;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;

final readonly class GetPreservationDashboardStatsHandler
{
    public function __construct(
        private PreservationCandidateCollector $collector,
        private PreservationAuditLogger $audit,
        private PreservationConfigurationResolver $configurations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetPreservationDashboardStatsQuery $query): array
    {
        $sealed = PreservationBatch::where('status', '!=', 'draft');
        $last = (clone $sealed)->orderByDesc('sealed_at')->orderByDesc('created_at')->first();
        $lastCheck = PreservationAuditLog::whereIn('action', [AuditAction::IntegrityVerified->value, AuditAction::IntegrityAlert->value])->orderByDesc('sequence')->first();
        $chain = $this->audit->verifyChain();

        $status = match (true) {
            ! $chain['ok'], $lastCheck?->action === AuditAction::IntegrityAlert->value => 'alert',
            $last === null => 'empty',
            default => 'verified',
        };

        return [
            'preserved_documents' => PreservationBatchItem::whereIn('preservation_batch_id', (clone $sealed)->select('id'))->count(),
            'worm_size_bytes' => (int) (clone $sealed)->sum('total_size_bytes'),
            'last_sealed_batch' => $last === null ? null : ['id' => $last->id, 'batch_number' => $last->batch_number, 'sealed_at' => $last->sealed_at?->toIso8601String()],
            'chain_status' => $status,
            'pending_unpreserved_documents' => $this->collector->pendingCount(),
            'retention_years' => max($this->configurations->current()->default_retention_years, (int) config('digital_preservation.minimum_retention_years')),
            'batches_by_status' => PreservationBatch::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all(),
            'last_verification_at' => $lastCheck?->created_at?->toIso8601String(),
        ];
    }
}
