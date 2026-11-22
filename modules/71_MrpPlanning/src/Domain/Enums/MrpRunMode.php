<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Regenerative replans every item; net change keeps the proposals of the items whose inputs did not change. */
enum MrpRunMode: string
{
    case RegenerativeFull = 'RegenerativeFull';
    case NetChange = 'NetChange';
}
