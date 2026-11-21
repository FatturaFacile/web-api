<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Exceptions;

/**
 * The period, assignment, template or swap does not exist in the tenant (HTTP 404).
 */
final class ShiftNotFoundException extends ShiftPlanningException {}
