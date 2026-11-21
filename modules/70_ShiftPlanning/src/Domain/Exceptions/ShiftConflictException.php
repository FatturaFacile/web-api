<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Exceptions;

/**
 * Somebody else changed the same shift first: an open shift already claimed, a swap already decided (HTTP 409).
 */
final class ShiftConflictException extends ShiftPlanningException {}
