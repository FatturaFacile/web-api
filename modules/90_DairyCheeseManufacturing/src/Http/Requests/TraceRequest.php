<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class TraceRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'viewAny';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:forward,backward'],
            'type' => ['required', 'in:delivery,test,batch,wheel'],
            'id' => ['nullable', 'uuid', 'required_without:serial'],
            'serial' => ['nullable', 'string', 'max:60', 'required_without:id'],
        ];
    }
}
