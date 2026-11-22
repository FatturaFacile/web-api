<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

use Modules\Manufacturing\Exceptions\ManufacturingOperationException;

/**
 * An operator action on a phase and the states it may start from: the transition table of the state machine.
 */
enum PhaseAction: string
{
    case TakeOver = 'take_over';
    case StartSetup = 'start_setup';
    case StartProduction = 'start_production';
    case Suspend = 'suspend';
    case Resume = 'resume';
    case DeclareProduction = 'declare_production';
    case ConsumeMaterial = 'consume_material';
    case Close = 'close';

    /**
     * The states the action may start from.
     *
     * @return list<PhaseStatus>
     */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::TakeOver => [PhaseStatus::Available, PhaseStatus::Assigned, PhaseStatus::Suspended],
            self::StartSetup => [PhaseStatus::Assigned],
            self::StartProduction => [PhaseStatus::Assigned, PhaseStatus::Setup, PhaseStatus::Running],
            self::Suspend => [PhaseStatus::Setup, PhaseStatus::Running],
            self::Resume => [PhaseStatus::Suspended],
            self::DeclareProduction => [PhaseStatus::Running, PhaseStatus::Suspended],
            self::ConsumeMaterial => [PhaseStatus::Setup, PhaseStatus::Running, PhaseStatus::Suspended],
            self::Close => [PhaseStatus::Running, PhaseStatus::Suspended],
        };
    }

    /**
     * Whether the action may start from the state.
     */
    public function isAllowedFrom(PhaseStatus $status): bool
    {
        return in_array($status, $this->allowedFrom(), true);
    }

    /**
     * Refuses the action from a state it does not start from.
     */
    public function assertAllowedFrom(PhaseStatus $status): void
    {
        if (! $this->isAllowedFrom($status)) {
            throw new ManufacturingOperationException("Action [{$this->value}] is not allowed while the phase is {$status->value}.", 'status');
        }
    }
}
