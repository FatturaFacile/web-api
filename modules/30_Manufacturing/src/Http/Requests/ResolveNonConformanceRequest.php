<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Modules\Manufacturing\Services\QualityManagementService;

/**
 * Validation of the closing disposition of a non-conformance.
 */
final class ResolveNonConformanceRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disposition' => ['required', 'string', 'in:'.implode(',', QualityManagementService::FinalDispositions)],
            'corrective_action' => ['required', 'string', 'max:5000'],
        ];
    }
}
