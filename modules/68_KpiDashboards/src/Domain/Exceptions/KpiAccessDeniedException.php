<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Exceptions;

/**
 * The member may not open the cockpit or drill into the KPI (role or attribute rule of Mod 004).
 */
final class KpiAccessDeniedException extends KpiDashboardException {}
