<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\Ticket;

/**
 * Body-less action of the dressing desk (start the course, mark a no-show).
 */
final class OperateActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operate', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
