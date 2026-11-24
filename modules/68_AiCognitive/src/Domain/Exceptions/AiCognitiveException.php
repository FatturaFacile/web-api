<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Exceptions;

use RuntimeException;

/**
 * A business rule of the AI layer refused the operation. The message is safe to show to the user.
 */
class AiCognitiveException extends RuntimeException {}
