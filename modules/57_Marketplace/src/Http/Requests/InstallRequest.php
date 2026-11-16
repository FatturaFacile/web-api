<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Requests;

/**
 * Installation of a connector of the catalog, with the scopes the company grants it.
 */
final class InstallRequest extends MarketplaceFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'connector_key' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'granted_scopes' => ['required', 'array', 'min:1', 'max:30'],
            'granted_scopes.*' => ['string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\.(read|write)$/'],
        ];
    }
}
