<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum ResidencyScopeType: string
{
    case TenantDefault = 'TENANT_DEFAULT';
    case LegalEntityOverride = 'LEGAL_ENTITY_OVERRIDE';
}
