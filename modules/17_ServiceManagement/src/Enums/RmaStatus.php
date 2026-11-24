<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * Depot repair (RMA) state machine.
 *
 * awaiting_arrival -> in_diagnosis | cancelled
 * in_diagnosis -> awaiting_estimate_approval | awaiting_parts | in_repair_testing | ready_for_return | cancelled
 * awaiting_estimate_approval -> awaiting_parts | in_repair_testing | ready_for_return
 * awaiting_parts -> in_repair_testing
 * in_repair_testing -> ready_for_return
 * ready_for_return -> returned
 * returned -> closed
 */
enum RmaStatus: string
{
    case AwaitingArrival = 'awaiting_arrival';
    case InDiagnosis = 'in_diagnosis';
    case AwaitingEstimateApproval = 'awaiting_estimate_approval';
    case AwaitingParts = 'awaiting_parts';
    case InRepairTesting = 'in_repair_testing';
    case ReadyForReturn = 'ready_for_return';
    case Returned = 'returned';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AwaitingArrival => [self::InDiagnosis, self::Cancelled],
            self::InDiagnosis => [
                self::AwaitingEstimateApproval,
                self::AwaitingParts,
                self::InRepairTesting,
                self::ReadyForReturn,
                self::Cancelled,
            ],
            self::AwaitingEstimateApproval => [self::AwaitingParts, self::InRepairTesting, self::ReadyForReturn],
            self::AwaitingParts => [self::InRepairTesting],
            self::InRepairTesting => [self::ReadyForReturn],
            self::ReadyForReturn => [self::Returned],
            self::Returned => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    /**
     * Italian label used by the Kanban board and the public tracking page.
     */
    public function label(): string
    {
        return match ($this) {
            self::AwaitingArrival => 'Attesa arrivo merce',
            self::InDiagnosis => 'In diagnosi',
            self::AwaitingEstimateApproval => 'Attesa approvazione preventivo',
            self::AwaitingParts => 'Attesa ricambi',
            self::InRepairTesting => 'In riparazione / collaudo',
            self::ReadyForReturn => 'Pronto per la restituzione',
            self::Returned => 'Restituito',
            self::Closed => 'Chiuso',
            self::Cancelled => 'Annullato',
        };
    }

    /**
     * Kanban columns: every status except cancelled, in workflow order.
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        $columns = [];

        foreach (self::cases() as $status) {
            if ($status !== self::Cancelled) {
                $columns[] = $status;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
