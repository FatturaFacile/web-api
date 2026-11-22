<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Registration of an edge gateway.
 */
final class StoreEdgeGatewayRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }
}
