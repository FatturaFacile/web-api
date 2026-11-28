<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Exceptions;

use DomainException;

/**
 * A global master data record (no tenant) is shared by every company: a tenant cannot add translations to it.
 */
final class ReadOnlyResourceException extends DomainException
{
    public function __construct(public readonly string $resource, public readonly string $id)
    {
        parent::__construct("The {$resource} record [{$id}] is shared by every company and cannot be translated by a tenant.");
    }
}
