<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTenantPreferencesRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Http\JsonResponse;

class TenantPreferencesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantPreferences $preferences) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->preferences->all(), __('settings.messages.preferences_retrieved'));
    }

    public function update(UpdateTenantPreferencesRequest $request, string $group): JsonResponse
    {
        abort_unless(in_array($group, TenantPreferences::GROUPS, true), 404, __('sdi.unknown_preference_group'));

        // Only keys defined for the group are validated and kept; null clears a preference.
        return $this->successResponse(
            $this->preferences->update($group, $request->validated()),
            __('settings.messages.preferences_updated')
        );
    }
}
