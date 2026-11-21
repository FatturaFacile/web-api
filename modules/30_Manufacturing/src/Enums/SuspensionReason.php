<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Reason code of a suspension (Italian: causale di sospensione). Labels are translated by the clients.
 */
enum SuspensionReason: string
{
    case MachineBreakdown = 'machine_breakdown';
    case MaterialShortage = 'material_shortage';
    case QualityHold = 'quality_hold';
    case Break = 'break';
    case ShiftHandover = 'shift_handover';
    case ToolChange = 'tool_change';
    case Other = 'other';
}
