<?php

declare(strict_types=1);

namespace Modules\Copilot\Semantic;

use InvalidArgumentException;

/**
 * The model asked for something the Semantic Query DSL does not allow. The message is returned to the model so it can fix
 * the query; it never contains data.
 */
final class SemanticQueryException extends InvalidArgumentException {}
