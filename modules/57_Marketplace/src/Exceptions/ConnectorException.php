<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * A failure of a connector while talking to an external system. The message is stored in the sync run log, so it must
 * never contain a credential.
 */
final class ConnectorException extends RuntimeException {}
