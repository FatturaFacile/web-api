<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

/**
 * An action that only needs the manage ability and no input (cleaning a tank, releasing or rejecting a delivery).
 */
final class ManageRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
