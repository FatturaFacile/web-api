<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Applies a batch of offline operations queued by the mobile client, in order, each in its own transaction.
 *
 * Operation: {"client_uuid": "...", "type": "status_transition|part_consumption|service_report", "work_order_id": "...", "payload": {...}}.
 * Every operation is reported as `applied`, `duplicate` (the same `client_uuid` was applied before: nothing changed) or
 * `rejected` (with field errors; the client keeps it for the user to fix). One rejection never blocks the rest.
 */
final readonly class ProcessMobileSyncBatchHandler
{
    public function __construct(
        private AdvanceFieldWorkOrderStatusHandler $advance,
        private RecordVanStockPartConsumptionHandler $consume,
        private SignAndCloseServiceReportHandler $sign,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $operations, ?string $actorUserId = null): array
    {
        $results = [];

        foreach ($operations as $operation) {
            $uuid = (string) ($operation['client_uuid'] ?? '');
            $type = (string) ($operation['type'] ?? '');

            try {
                $results[] = ['client_uuid' => $uuid, 'type' => $type] + $this->apply($type, $operation, $actorUserId);
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $uuid, 'type' => $type, 'status' => 'rejected', 'errors' => $e->errors()];
            } catch (ModelNotFoundException) {
                $results[] = ['client_uuid' => $uuid, 'type' => $type, 'status' => 'rejected', 'errors' => ['work_order_id' => ['not_found']]];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function apply(string $type, array $operation, ?string $actorUserId): array
    {
        $workOrderId = (string) ($operation['work_order_id'] ?? '');
        $payload = (array) ($operation['payload'] ?? []);

        return match ($type) {
            'status_transition' => $this->transition($workOrderId, $payload),
            'part_consumption' => $this->part($workOrderId, (string) ($operation['client_uuid'] ?? ''), $payload, $actorUserId),
            'service_report' => $this->report($workOrderId, (string) ($operation['client_uuid'] ?? ''), $payload, $actorUserId),
            default => throw ValidationException::withMessages(['type' => ['unsupported']]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function transition(string $workOrderId, array $payload): array
    {
        $data = Validator::make($payload, ['status' => ['required', 'in:en_route,on_site'], 'at' => ['nullable', 'date']])->validate();
        $current = (string) WorkOrder::query()->whereKey($workOrderId)->firstOrFail()->status;

        // A replayed offline event for a step the work order already went through (or went past) changes nothing.
        if (($this->journeyRank($current) ?? 0) >= $this->journeyRank($data['status'])) {
            return ['status' => 'duplicate', 'work_order_status' => $current];
        }

        $workOrder = ($this->advance)($workOrderId, $data['status'], isset($data['at']) ? CarbonImmutable::parse($data['at']) : null);

        return ['status' => 'applied', 'work_order_status' => (string) $workOrder->status];
    }

    /**
     * Position of a status along the technician journey; statuses after it (in progress, completed...) rank beyond on-site.
     */
    private function journeyRank(string $status): ?int
    {
        return match ($status) {
            'dispatched' => 0,
            'en_route' => 1,
            'on_site' => 2,
            'in_progress', 'completed', 'closed', 'invoiced' => 3,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function part(string $workOrderId, string $uuid, array $payload, ?string $actorUserId): array
    {
        $data = Validator::make($payload + ['client_uuid' => $uuid], [
            'client_uuid' => ['required', 'string', 'max:64'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'lot_or_serial_id' => ['nullable', 'uuid'],
            'lot_number' => ['nullable', 'string', 'max:60'],
        ])->validate();

        $consumption = ($this->consume)(new RecordVanStockPartConsumptionCommand(
            workOrderId: $workOrderId,
            productId: $data['product_id'],
            quantity: (string) $data['quantity'],
            unitPrice: (string) ($data['unit_price'] ?? '0'),
            lotOrSerialId: $data['lot_or_serial_id'] ?? null,
            lotNumber: $data['lot_number'] ?? null,
            clientUuid: $data['client_uuid'],
            actorUserId: $actorUserId,
        ));

        return $this->outcome($consumption, 'consumption_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function report(string $workOrderId, string $uuid, array $payload, ?string $actorUserId): array
    {
        $data = Validator::make($payload + ['client_uuid' => $uuid], SignServiceReportRules::rules())->validate();

        $report = ($this->sign)(SignServiceReportRules::command($workOrderId, $data, $actorUserId));

        return $this->outcome($report, 'report_id') + ['report_number' => (string) $report->report_number, 'signature_sha256_hash' => (string) $report->signature_sha256_hash];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(PartsConsumption|DigitalServiceReport $record, string $idKey): array
    {
        return ['status' => $record->wasRecentlyCreated ? 'applied' : 'duplicate', $idKey => (string) $record->id];
    }
}
