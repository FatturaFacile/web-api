<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Exceptions;

use RuntimeException;

/**
 * A rate provider could not deliver a valid feed (network, status, schema). The run fails; no rate is invented.
 */
final class RateProviderException extends RuntimeException {}
