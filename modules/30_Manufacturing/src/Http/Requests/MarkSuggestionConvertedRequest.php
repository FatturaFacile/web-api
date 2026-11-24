<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the procurement document a purchase suggestion was converted into.
 */
final class MarkSuggestionConvertedRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'procurement_document_type' => ['required', 'string', 'in:purchase_requisition,purchase_order'],
            'procurement_document_id' => ['required', 'uuid'],
        ];
    }
}
