<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\SchoolBooking;

/**
 * Body-less action on a school booking that managers perform (confirm, complete).
 */
final class ManageActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', SchoolBooking::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
