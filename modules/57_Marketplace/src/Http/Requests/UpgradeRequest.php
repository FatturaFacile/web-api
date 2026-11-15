<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Requests;

/**
 * The extra scopes granted to a new version of a connector.
 */
final class UpgradeRequest extends MarketplaceFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'granted_scopes' => ['nullable', 'array', 'max:30'],
            'granted_scopes.*' => ['string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\.(read|write)$/'],
        ];
    }
}
