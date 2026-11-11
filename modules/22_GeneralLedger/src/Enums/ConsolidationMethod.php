<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

/**
 * How a member entity enters the consolidated statements. The concepts are those of IFRS 10 / IAS 28, OIC 17 and the
 * Swiss CO art. 963 and following; no compliance with any of them is claimed.
 *
 * Full: every account of the entity is added line by line at 100 %, the share of the other owners is shown as
 * non-controlling interests. Equity: the entity is not added; the investment of the investor is brought to the
 * ownership share of the net assets of the entity and the share of its result is shown on its own line. Proportional is
 * stored as a flag but is not implemented: a run that includes it is refused instead of guessing.
 */
enum ConsolidationMethod: string
{
    case Full = 'full';
    case Proportional = 'proportional';
    case Equity = 'equity';
}
