<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Requests;

/**
 * The configuration of an installation: the values of the fields of the connector schema (secrets included) and, optionally,
 * a lower rate limit. The schema itself validates the fields.
 */
final class ConfigureRequest extends MarketplaceFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'config' => ['present', 'array', 'max:30'],
            'rate_limit_per_minute' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
