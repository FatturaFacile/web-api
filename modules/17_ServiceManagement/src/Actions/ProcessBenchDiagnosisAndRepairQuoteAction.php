<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\DTOs\BenchDiagnosisDTO;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;

/**
 * Command "ProcessBenchDiagnosisAndRepairQuote": record the bench diagnosis and lines, then route the RMA by
 * warranty coverage and customer pre-authorized threshold (warranty provision, auto-approval or customer estimate).
 */
final readonly class ProcessBenchDiagnosisAndRepairQuoteAction
{
    public function __construct(
        private RmaServiceInterface $rmaService,
    ) {}

    /**
     * @return array{rma: DepotRepairRmaOrder, estimate_token: string|null, estimate_url: string|null}
     */
    public function execute(DepotRepairRmaOrder $rma, BenchDiagnosisDTO $dto, ?string $actorUserId = null): array
    {
        $result = $this->rmaService->diagnose($rma, $dto, $actorUserId);
        $token = $result['estimate_token'];

        return [
            'rma' => $result['rma'],
            'estimate_token' => $token,
            'estimate_url' => $token !== null ? $this->rmaService->estimateUrl($token) : null,
        ];
    }
}
