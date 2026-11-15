<?php

declare(strict_types=1);

namespace Modules\CRM\Exceptions;

use InvalidArgumentException;

/**
 * Extends InvalidArgumentException so existing callers that catch it keep working.
 */
final class LeadAlreadyConvertedException extends InvalidArgumentException
{
    public static function forLead(string $leadId): self
    {
        return new self("Lead [{$leadId}] has already been converted.");
    }

    public static function mergedInto(string $leadId, string $survivorId): self
    {
        return new self("Lead [{$leadId}] was merged into lead [{$survivorId}] and cannot be converted.");
    }
}
