<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\RateSource;

final class SyncRatesRequest extends FormRequest
{
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
            'providers' => ['sometimes', 'array', 'min:1'],
            'providers.*' => ['string', Rule::in([RateSource::Ecb->value, RateSource::Snb->value, RateSource::Estv->value])],
        ];
    }
}
