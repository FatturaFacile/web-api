<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An action that takes no input (run the due invitations, install the stock templates, opt out through the survey link): the
 * request exists so every write endpoint goes through a FormRequest.
 */
final class NoInputRequest extends FormRequest
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
        return [];
    }
}
