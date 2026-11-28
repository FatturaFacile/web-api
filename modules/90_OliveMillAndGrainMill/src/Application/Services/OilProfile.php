<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Services;

use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * The category and origin designation of the oil a cycle produced: the declared certification of the delivery, lowered to what the lab
 * analysis allows (a lot declared DOP whose acidity is above the extra virgin limit is stored as virgin or lampante).
 */
final class OilProfile
{
    public function category(OliveDelivery $delivery, MillingCycle $cycle): OilCategory
    {
        $declared = $delivery->certification->oilCategory();

        if ($cycle->quality_class === null) {
            return $declared;
        }

        $measured = OilCategory::from($cycle->quality_class);

        return $measured->grade() >= $declared->grade() ? $declared : OilCategory::ofGrade($measured->grade());
    }

    public function origin(OliveDelivery $delivery, ?OriginDesignation $requested): OriginDesignation
    {
        return $requested ?? ($delivery->certification === Certification::Dop ? OriginDesignation::DopSpecifica : OriginDesignation::Italia);
    }
}
