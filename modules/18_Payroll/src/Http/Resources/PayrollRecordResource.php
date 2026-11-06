<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollRecord;

/**
 * A payroll record as the API shows it: its attributes, with decimals as strings, and the loaded relations.
 *
 * @mixin PayrollRecord
 */
final class PayrollRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'is_generic' => $this->resource->isGeneric()];
    }
}
