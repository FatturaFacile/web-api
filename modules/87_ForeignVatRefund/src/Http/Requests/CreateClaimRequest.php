<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

final class CreateClaimRequest extends PreviewClaimRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + ['notes' => ['nullable', 'string', 'max:2000']];
    }
}
