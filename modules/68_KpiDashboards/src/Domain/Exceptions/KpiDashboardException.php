<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Exceptions;

use RuntimeException;

/**
 * A KPI dashboard rule was violated (invalid layout, refresh interval, threshold).
 */
class KpiDashboardException extends RuntimeException {}
