<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Exceptions;

/**
 * A forecast or run that does not exist for the tenant (including one that belongs to another tenant).
 */
final class ForecastingNotFoundException extends \RuntimeException {}
