<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * A public reply is seen by the customer, an internal note only by staff.
 */
enum MessageVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
