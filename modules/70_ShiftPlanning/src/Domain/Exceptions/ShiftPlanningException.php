<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Exceptions;

use RuntimeException;

/**
 * A business-rule violation of the planner (HTTP 422).
 */
class ShiftPlanningException extends RuntimeException {}
