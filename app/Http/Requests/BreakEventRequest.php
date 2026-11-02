<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

class BreakEventRequest extends AttendanceMutationRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(TenantContext::class)->getTenantId() !== null;
    }
}
