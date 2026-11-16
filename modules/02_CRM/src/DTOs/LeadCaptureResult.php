<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

use Illuminate\Support\Collection;
use Modules\CRM\Models\Lead;

final readonly class LeadCaptureResult
{
    /**
     * @param  Collection<int, Lead>  $duplicates  existing leads matching the incoming data
     * @param  bool  $merged  true when no new lead was created and $lead is the pre-existing one that absorbed the data
     */
    public function __construct(
        public Lead $lead,
        public bool $merged,
        public Collection $duplicates,
    ) {}
}
