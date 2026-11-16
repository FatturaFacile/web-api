<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Colour scheme chosen by a user or set as the default of a tenant; `system` follows the operating system.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
