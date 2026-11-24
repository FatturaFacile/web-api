<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Concerns\ValidatesCompetenceDates;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePassiveInvoiceLineCompetenceRequest extends FormRequest
{
    use ValidatesCompetenceDates;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->competenceDateRules();
    }
}
