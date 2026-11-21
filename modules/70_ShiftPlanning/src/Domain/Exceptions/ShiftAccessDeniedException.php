<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Exceptions;

/**
 * The caller is not the employee or the manager the action requires (HTTP 403).
 */
final class ShiftAccessDeniedException extends ShiftPlanningException {}
