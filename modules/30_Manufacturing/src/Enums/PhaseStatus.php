<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Lifecycle state of a phase (Italian: fase di lavorazione) on the MES operator board. `Available` is virtual: an
 * operation nobody has taken over has no phase row yet.
 */
enum PhaseStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Setup = 'setup';
    case Running = 'running';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Whether a time interval can be open in this state.
     */
    public function hasOpenInterval(): bool
    {
        return in_array($this, [self::Setup, self::Running, self::Suspended], true);
    }

    /**
     * The actions the operator board offers in this state, in display order.
     *
     * @return list<PhaseAction>
     */
    public function allowedActions(): array
    {
        return array_values(array_filter(PhaseAction::cases(), fn (PhaseAction $action): bool => $action->isAllowedFrom($this)));
    }
}
