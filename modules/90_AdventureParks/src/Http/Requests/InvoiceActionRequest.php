<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\SchoolBooking;

/**
 * Generation of the electronic invoice of a school booking.
 */
final class InvoiceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('invoice', SchoolBooking::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
