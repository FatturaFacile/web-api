<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Manufacturing\Exceptions\MesEventRejectedException;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Support\MesProblem;

/**
 * Processes one event of the MES Core and answers its status. A malformed event, an event of another tenant than the
 * one the signing key was issued for, or an event that references unknown records is `rejected` with a problem
 * document, without failing the other events of the batch.
 *
 * - `OeeShiftClosed`, `OeeOrderCompleted`, `OeeSnapshotRevised`: OEE snapshots (see ApplyMesOeeSnapshot).
 * - `ProductionCountCheckpoint`: a production-count proposal for the operation.
 * - `MachineAlarmRaised`: recorded in the inbox only (no consumer yet).
 */
final readonly class MesFeedbackProcessor
{
    /** Highest payload schema version this API understands. */
    public const int SupportedSchemaVersion = 1;

    private const array SnapshotEvents = ['OeeShiftClosed', 'OeeOrderCompleted', 'OeeSnapshotRevised'];

    public function __construct(
        private ApplyMesOeeSnapshot $applySnapshot,
        private ProposeMesProductionCounts $proposeCounts,
        private MesInbox $inbox,
    ) {}

    /**
     * Processes the event of the authenticated tenant.
     *
     * @param  array<string, mixed>  $event
     * @return array{event_id: string|null, status: string, problem?: array<string, mixed>}
     */
    public function __invoke(array $event, string $tenantId): array
    {
        $eventId = isset($event['event_id']) && is_string($event['event_id']) ? $event['event_id'] : null;

        try {
            $validated = $this->validate($event);

            if ($validated['tenant_id'] !== $tenantId) {
                throw new MesEventRejectedException('The event belongs to another tenant than the signing key.', 'tenant.forbidden', 403);
            }

            return ['event_id' => $eventId, 'status' => $this->dispatch($validated)->value];
        } catch (MesEventRejectedException $exception) {
            return $this->rejected($eventId, $exception->status, $exception->problemCode, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function validate(array $event): array
    {
        $isSnapshot = in_array($event['event_type'] ?? null, self::SnapshotEvents, true);

        $validator = Validator::make($event, [
            'event_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'in:'.implode(',', [...self::SnapshotEvents, 'ProductionCountCheckpoint', 'MachineAlarmRaised'])],
            'schema_version' => ['required', 'integer', 'min:1'],
            'tenant_id' => ['required', 'uuid'],
            'machine_id' => ['required', 'uuid'],
            'work_order_id' => ['nullable', 'uuid'],
            'production_order_operation_id' => ['nullable', 'uuid'],
            'scope' => [$isSnapshot ? 'required' : 'nullable', 'in:Shift,Order'],
            'scope_key' => [$isSnapshot ? 'required' : 'nullable', 'string', 'max:64'],
            'revision' => [$isSnapshot ? 'required' : 'nullable', 'integer', 'min:1'],
            'policy_version' => [$isSnapshot ? 'required' : 'nullable', 'integer', 'min:0'],
            'as_of_event_time' => ['required', 'date'],
            'occurred_at' => ['required', 'date'],
            'coverage_pct' => ['nullable', 'numeric', 'between:0,100'],
            'metrics' => [$isSnapshot || ($event['event_type'] ?? null) === 'ProductionCountCheckpoint' ? 'required' : 'nullable', 'array'],
            'metrics.run_ms' => ['nullable', 'integer', 'min:0'],
            'metrics.setup_ms' => ['nullable', 'integer', 'min:0'],
            'metrics.alarm_ms' => ['nullable', 'integer', 'min:0'],
            'metrics.down_ms' => ['nullable', 'integer', 'min:0'],
            'metrics.good_count' => ['nullable', 'integer', 'min:0'],
            'metrics.scrap_count' => ['nullable', 'integer', 'min:0'],
            'metrics.delta_kwh' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['pointer' => '/'.str_replace('.', '/', $field), 'message' => $message];
                }
            }

            throw new MesEventRejectedException(json_encode($errors, JSON_THROW_ON_ERROR), 'event.invalid');
        }

        if ((int) $event['schema_version'] > self::SupportedSchemaVersion) {
            throw new MesEventRejectedException('The schema version of the event is not supported.', 'event.unsupported_schema');
        }

        if ($isSnapshot && $event['scope'] === 'Order' && empty($event['production_order_operation_id'])) {
            throw new MesEventRejectedException('An order snapshot needs the production order operation.', 'event.invalid');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        // The validated metrics hold only the checked keys; the snapshot keeps every metric the MES Core sent.
        $validated['metrics'] = is_array($event['metrics'] ?? null) ? $event['metrics'] : [];

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function dispatch(array $event): MesEventStatus
    {
        $tenantId = (string) $event['tenant_id'];

        if (! MfgMachineCenter::query()->where('tenant_id', $tenantId)->whereKey($event['machine_id'])->exists()) {
            throw new MesEventRejectedException('The machine does not exist.', 'machine.unknown');
        }

        if (! empty($event['production_order_operation_id'])
            && ! MfgProductionOrderOperation::query()->where('tenant_id', $tenantId)->whereKey($event['production_order_operation_id'])->exists()) {
            throw new MesEventRejectedException('The production order operation does not exist.', 'operation.unknown');
        }

        return match ($event['event_type']) {
            'ProductionCountCheckpoint' => $this->countCheckpoint($event),
            'MachineAlarmRaised' => $this->alarm($event),
            default => ($this->applySnapshot)($event),
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function countCheckpoint(array $event): MesEventStatus
    {
        if (empty($event['production_order_operation_id'])) {
            throw new MesEventRejectedException('A count checkpoint needs the production order operation.', 'event.invalid');
        }

        return DB::transaction(function () use ($event): MesEventStatus {
            if (! $this->inbox->claim((string) $event['event_id'], (string) $event['tenant_id'], (string) $event['event_type'])) {
                return MesEventStatus::Duplicate;
            }

            ($this->proposeCounts)(
                (string) $event['tenant_id'],
                (string) $event['production_order_operation_id'],
                (string) ($event['metrics']['good_count'] ?? 0),
                (string) ($event['metrics']['scrap_count'] ?? 0),
                (string) $event['as_of_event_time'],
            );

            return MesEventStatus::Applied;
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function alarm(array $event): MesEventStatus
    {
        return $this->inbox->claim((string) $event['event_id'], (string) $event['tenant_id'], (string) $event['event_type'])
            ? MesEventStatus::Applied
            : MesEventStatus::Duplicate;
    }

    /**
     * @return array{event_id: string|null, status: string, problem: array<string, mixed>}
     */
    private function rejected(?string $eventId, int $status, string $code, string $message): array
    {
        $errors = [];
        $detail = $message;
        $decoded = json_decode($message, true);

        if ($code === 'event.invalid' && is_array($decoded)) {
            $errors = $decoded;
            $detail = 'The event is not valid.';
        }

        return [
            'event_id' => $eventId,
            'status' => MesEventStatus::Rejected->value,
            'problem' => MesProblem::document($status, $code, 'Event rejected', $detail, false, $errors),
        ];
    }
}
