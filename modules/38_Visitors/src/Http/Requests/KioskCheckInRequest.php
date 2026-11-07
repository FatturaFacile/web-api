<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Visitors\Models\VisitorVisit;

/**
 * Check-in at the kiosk: an expected visit of today, or a walk-in with the visitor data and the host. The tenant is the
 * one of the kiosk, set by its middleware.
 */
final class KioskCheckInRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visit_id' => ['required_without:visitor', 'nullable', 'uuid', $this->tenantOwns('visitor_visits')->where('status', VisitorVisit::Expected)],
            'visitor' => ['required_without:visit_id', 'nullable', 'array'],
            'visitor.first_name' => ['required_with:visitor', 'string', 'max:100'],
            'visitor.last_name' => ['required_with:visitor', 'string', 'max:100'],
            'visitor.company_name' => ['nullable', 'string', 'max:150'],
            'visitor.email' => ['nullable', 'email', 'max:255'],
            'visitor.phone' => ['nullable', 'string', 'max:40'],
            'host_user_id' => ['required_with:visitor', 'nullable', 'uuid', $this->tenantMember()],
            'purpose' => ['nullable', 'string', 'max:500'],
            'badge_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['visit_id.exists' => 'The visit is not expected here.'];
    }
}
