<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Reason code of scrapped pieces (Italian: causale di difetto). Labels are translated by the clients.
 */
enum DefectReason: string
{
    case Dimensional = 'dimensional';
    case Surface = 'surface';
    case MaterialDefect = 'material_defect';
    case ToolWear = 'tool_wear';
    case SetupError = 'setup_error';
    case OperatorError = 'operator_error';
    case MachineFault = 'machine_fault';
    case Other = 'other';
}
