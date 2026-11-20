<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Exceptions;

use RuntimeException;

/**
 * A forecasting request that cannot be served with the data of the tenant.
 */
final class ForecastingException extends RuntimeException {}
