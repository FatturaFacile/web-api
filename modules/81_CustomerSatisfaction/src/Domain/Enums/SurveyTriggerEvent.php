<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum SurveyTriggerEvent: string
{
    use EnumValues;

    case TicketClosed = 'ticket_closed';
    case OrderDelivered = 'order_delivered';
    case FieldInterventionSigned = 'field_intervention_signed';
    case OnboardingCompleted = 'onboarding_completed';
    case SemiAnnualActiveClient = 'semi_annual_active_client';
}
