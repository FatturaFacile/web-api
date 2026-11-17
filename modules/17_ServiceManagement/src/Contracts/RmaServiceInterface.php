<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Modules\ServiceManagement\DTOs\BenchDiagnosisDTO;
use Modules\ServiceManagement\DTOs\CompleteRmaRepairDTO;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\DTOs\ReceiveRmaDTO;
use Modules\ServiceManagement\DTOs\ReturnRmaDTO;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

interface RmaServiceInterface
{
    /**
     * @return array{rma: DepotRepairRmaOrder, tracking_token: string}
     */
    public function issue(SerializedEquipmentPassport $passport, IssueRmaDTO $dto, ?string $actorUserId = null): array;

    public function receive(DepotRepairRmaOrder $rma, ReceiveRmaDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder;

    /**
     * @return array{rma: DepotRepairRmaOrder, estimate_token: string|null}
     */
    public function diagnose(DepotRepairRmaOrder $rma, BenchDiagnosisDTO $dto, ?string $actorUserId = null): array;

    public function approveEstimate(
        DepotRepairRmaOrder $rma,
        string $decidedVia,
        ?string $actorUserId = null,
        ?string $decidedByName = null,
        ?string $ip = null,
        ?string $note = null,
    ): DepotRepairRmaOrder;

    public function declineEstimate(
        DepotRepairRmaOrder $rma,
        string $decidedVia,
        ?string $actorUserId = null,
        ?string $decidedByName = null,
        ?string $ip = null,
        ?string $note = null,
    ): DepotRepairRmaOrder;

    /**
     * @return array{rma: DepotRepairRmaOrder, estimate_token: string}
     */
    public function resendEstimate(DepotRepairRmaOrder $rma, ?string $actorUserId = null): array;

    public function markPartsReceived(DepotRepairRmaOrder $rma, ?string $actorUserId = null): DepotRepairRmaOrder;

    public function completeRepair(DepotRepairRmaOrder $rma, CompleteRmaRepairDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder;

    public function returnToCustomer(DepotRepairRmaOrder $rma, ReturnRmaDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder;

    public function close(DepotRepairRmaOrder $rma, ?string $actorUserId = null): DepotRepairRmaOrder;

    public function cancel(DepotRepairRmaOrder $rma, string $reason, ?string $actorUserId = null): DepotRepairRmaOrder;

    public function regenerateTrackingToken(DepotRepairRmaOrder $rma, ?string $actorUserId = null): string;

    /**
     * Resolve an RMA from a raw public estimate token and bind its tenant. Throws 404 on unknown/expired token.
     */
    public function resolveByEstimateToken(string $rawToken): DepotRepairRmaOrder;

    /**
     * Resolve an RMA from its number + raw tracking token and bind its tenant. Throws 404 on any mismatch.
     */
    public function resolveByTrackingToken(string $rmaNumber, string $rawToken): DepotRepairRmaOrder;

    /**
     * @return array{rma_number: string, serial_number: string, product_name: string|null, customer_name: string|null, return_address: string|null, barcode_payload: string, tracking_url: string|null, tracking_token?: string}
     */
    public function label(DepotRepairRmaOrder $rma, ?string $rawTrackingToken = null): array;

    public function estimateUrl(string $rawToken): string;

    /**
     * @param  array{warranty_status?: string|null, customer_id?: string|null, include_closed?: bool}  $filters
     * @return list<array{status: string, label: string, count: int, items: list<array<string, mixed>>}>
     */
    public function board(array $filters = []): array;
}
