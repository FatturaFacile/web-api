<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Frontier;

/**
 * The tax position of a worker resident in Italy under the agreement of 23 December 2020.
 */
enum FrontierRegime: string
{
    /** Transitional regime of art. 9: taxed only in Switzerland, tariffs A, B, C, H. */
    case Old = 'old';

    /** Art. 3 par. 1: withholding in Switzerland and ordinary taxation in Italy, tariffs R, S, T, U. */
    case New = 'new';

    /** Resident in Italy but outside the definition of art. 2 lett. b. */
    case NotCovered = 'not_covered';
}
