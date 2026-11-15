<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behaviour of the marketplace payloads: the access decision lives in the module.access middleware and the policy.
 */
abstract class MarketplaceFormRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }
}
