<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum WarrantyPolicyType: string
{
    case StatutoryCh24m = 'statutory_ch_24m';
    case StatutoryItB2c24m = 'statutory_it_b2c_24m';
    case StatutoryItB2b12m = 'statutory_it_b2b_12m';
    case ExtendedCarePack = 'extended_care_pack';
}
