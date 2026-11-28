<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

/**
 * An action that only needs the settle ability and no input (approving a settlement).
 */
final class SettleRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'settle';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
