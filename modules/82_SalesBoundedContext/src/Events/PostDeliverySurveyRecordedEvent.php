<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class PostDeliverySurveyRecordedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $surveyId,
        public string $npsClassification,
        public bool $detractorAlertTriggered,
    ) {}
}
