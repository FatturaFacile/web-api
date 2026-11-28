<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Exceptions;

use DomainException;

/**
 * A statutory system key (is_locked = true) cannot be overridden or edited through the tenant APIs.
 */
final class LockedTranslationException extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("The translation key [{$key}] is locked and cannot be changed.");
    }
}
