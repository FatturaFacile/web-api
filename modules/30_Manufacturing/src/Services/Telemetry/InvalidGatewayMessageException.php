<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use RuntimeException;

/**
 * A gateway message that does not have the shape of its protocol. The ingestion reports it and goes on with the rest of
 * the batch.
 */
final class InvalidGatewayMessageException extends RuntimeException {}
